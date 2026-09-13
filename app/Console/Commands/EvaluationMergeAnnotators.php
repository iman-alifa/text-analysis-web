<?php

namespace App\Console\Commands;

use App\Services\Evaluation\GoldenDatasetService;
use App\Support\TextFingerprint;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gabungkan dua berkas ekspor `/anotasi` yang dikerjakan TERPISAH oleh dua
 * anotator menjadi satu berkas `id, text, label, label_2, aspects, segment,
 * note` - bentuk yang dikenali `evaluation:import` dan unggahan "Unggah
 * Anotasi" pada dataset yang sudah dibuat lewat menu Ambil Sampel.
 *
 * Alur yang DIDUKUNG aplikasi anotasi itu sendiri (anotator kedua membuka
 * berkas HASIL EKSPOR anotator pertama, lalu beralih mode "label_2 - anotator
 * kedua") sudah menghasilkan satu berkas dengan kedua kolom terisi - berkas
 * begitu bisa langsung dipakai `evaluation:import` tanpa perintah ini.
 *
 * Perintah ini untuk keadaan yang jauh lebih umum terjadi: dua anotator
 * bekerja dari salinan berkas kosong yang sama secara independen, sehingga
 * masing-masing pulang dengan jawabannya sendiri di kolom `label` (bukan satu
 * di `label` dan satu di `label_2`). Tanpa digabung, mengunggah keduanya
 * bergantian lewat "Unggah Anotasi" akan MENIMPA label anotator pertama
 * dengan label anotator kedua - bukan menghasilkan pasangan untuk kappa.
 *
 * Dicocokkan lewat kolom `id` (sama seperti pencocokan unggahan tunggal di
 * GoldenSamplingService::applyAnnotations), dengan sidik jari teks sebagai
 * cadangan bila id tidak ada.
 */
class EvaluationMergeAnnotators extends Command
{
    protected $signature = 'evaluation:merge-annotators
        {file1 : Ekspor anotator pertama (jadi label emas / gold)}
        {file2 : Ekspor anotator kedua (jadi pembanding kappa / label_2)}
        {--out= : Tulis hasil gabungan ke sini (bawaan: di samping file pertama)}';

    protected $description = 'Gabungkan dua berkas ekspor anotasi terpisah menjadi satu berkas label + label_2';

    public function handle(GoldenDatasetService $golden): int
    {
        $path1 = $this->argument('file1');
        $path2 = $this->argument('file2');

        foreach ([$path1, $path2] as $p) {
            if (! is_file($p)) {
                $this->error("Berkas tidak ditemukan: {$p}");

                return self::FAILURE;
            }
        }

        try {
            $baris1 = $this->bacaBerkas($golden, $path1);
            $baris2 = $this->bacaBerkas($golden, $path2);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // id sebagai kunci utama - lebih tahan terhadap teks yang sedikit
        // tersunting (spasi dirapikan, dsb) daripada sidik jari saja.
        $perId2 = collect($baris2)->filter(fn ($r) => $r['id'] !== null)->keyBy('id');
        $perHash2 = collect($baris2)->keyBy('hash');
        $terpakai2 = [];

        $gabungan = [];
        $berpasangan = 0;

        foreach ($baris1 as $r1) {
            $r2 = ($r1['id'] !== null ? $perId2->get($r1['id']) : null)
                ?? $perHash2->get($r1['hash']);

            if ($r2) {
                $terpakai2[] = $r2['id'] ?? $r2['hash'];
                $berpasangan++;
            }

            $gabungan[] = [
                'id' => $r1['id'] ?? $r2['id'] ?? '',
                'text' => $r1['text'],
                'label' => $r1['label'] ?? '',
                'label_2' => $r2['label'] ?? '',
                'aspects' => $r1['aspects'] ?: ($r2['aspects'] ?? ''),
                'segment' => $r1['segment'] ?: ($r2['segment'] ?? ''),
                'note' => $this->gabungCatatan($r1['note'], $r2['note'] ?? null),
            ];
        }

        $hanyaDiFile2 = collect($baris2)
            ->reject(fn ($r) => in_array($r['id'] ?? $r['hash'], $terpakai2, true))
            ->count();

        $this->info('Baris anotator pertama: '.count($baris1));
        $this->info('Baris anotator kedua: '.count($baris2));
        $this->info("Baris berpasangan (dianotasi ganda): {$berpasangan}");

        if ($berpasangan < 100) {
            $this->warn(
                "Hanya {$berpasangan} baris berpasangan. Panduan evaluasi meminta minimal 100 baris ".
                'beranotasi ganda supaya kappa antar-anotator tidak terlalu bising untuk dipercaya.'
            );
        }

        if ($hanyaDiFile2 > 0) {
            $this->warn(
                "{$hanyaDiFile2} baris pada berkas kedua tidak ditemukan pada berkas pertama (id/teks tidak cocok) - ".
                'dilewati, karena baris gold harus berasal dari anotator pertama. Periksa apakah kedua berkas '.
                'benar-benar berasal dari sampel yang sama.'
            );
        }

        $this->tampilkanKappa($golden, $gabungan);

        $keluar = $this->option('out') ?: $this->namaBawaan($path1);
        $this->tulisCsv($keluar, $gabungan);

        $this->info("Berkas gabungan ditulis ke: {$keluar}");
        $this->comment(
            'Langkah berikutnya: unggah berkas ini lewat "Unggah Anotasi" pada halaman dataset '.
            '(kalau datasetnya sudah dibuat lewat Ambil Sampel), atau `php artisan evaluation:import '.
            escapeshellarg($keluar).' --freeze` untuk membuat dataset baru.'
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{id: int|string|null, text: string, label: ?string, aspects: string, segment: string, note: ?string, hash: string}>
     */
    private function bacaBerkas(GoldenDatasetService $golden, string $path): array
    {
        $mentah = str_ends_with(strtolower($path), '.csv')
            ? $golden->readCsv($path)
            : $golden->readSpreadsheet($path);

        if (empty($mentah)) {
            throw new \RuntimeException("Berkas kosong atau tidak terbaca: {$path}");
        }

        $header = array_map(fn ($k) => mb_strtolower(trim((string) $k)), array_shift($mentah));
        $peta = array_flip($header);

        if (! isset($peta['text'])) {
            throw new \RuntimeException(
                "Kolom `text` tidak ditemukan pada {$path}. Berkas harus berasal dari ekspor /anotasi."
            );
        }

        $hasil = [];

        foreach ($mentah as $kolom) {
            $teks = trim((string) ($kolom[$peta['text']] ?? ''));

            if ($teks === '') {
                continue;
            }

            // Anotator boleh saja lupa mengganti mode ke "label_2" - jawabannya
            // tetap ada di kolom `label` pada berkasnya sendiri. Diambil dari
            // kolom mana pun yang terisi supaya perintah ini tidak menuntut
            // kedua anotator memakai mode yang "benar".
            $labelMentah = $kolom[$peta['label']] ?? null;
            if (blank($labelMentah) && isset($peta['label_2'])) {
                $labelMentah = $kolom[$peta['label_2']] ?? null;
            }

            $hasil[] = [
                'id' => isset($peta['id']) && is_numeric($kolom[$peta['id']] ?? null)
                    ? (int) $kolom[$peta['id']]
                    : null,
                'text' => $teks,
                'label' => $golden->normalizeLabel($labelMentah),
                'aspects' => isset($peta['aspects']) ? trim((string) ($kolom[$peta['aspects']] ?? '')) : '',
                'segment' => isset($peta['segment']) ? trim((string) ($kolom[$peta['segment']] ?? '')) : '',
                'note' => isset($peta['note']) ? (trim((string) ($kolom[$peta['note']] ?? '')) ?: null) : null,
                'hash' => TextFingerprint::hash($teks),
            ];
        }

        return $hasil;
    }

    private function gabungCatatan(?string $a, ?string $b): string
    {
        $bagian = array_filter([$a, $b], fn ($v) => filled($v));

        return implode(' | ', $bagian);
    }

    /**
     * Pratinjau kappa antar-anotator, sebelum berkasnya benar-benar diunggah -
     * supaya kesepakatan yang terlalu rendah ketahuan di sini, bukan setelah
     * dataset dibekukan.
     */
    private function tampilkanKappa(GoldenDatasetService $golden, array $gabungan): void
    {
        $items = collect($gabungan)->map(fn ($r) => (object) [
            'gold_sentiment' => $golden->normalizeLabel($r['label']),
            'second_sentiment' => $golden->normalizeLabel($r['label_2']),
        ]);

        $reliabilitas = $golden->annotationReliability($items);

        if ($reliabilitas === null) {
            $this->comment('Belum cukup baris berpasangan untuk menghitung kappa.');

            return;
        }

        $this->info(sprintf(
            'Kappa antar-anotator: %.3f (%s), kesepakatan %.1f%% atas %d baris.',
            $reliabilitas['cohen_kappa'],
            $reliabilitas['label'],
            $reliabilitas['agreement'],
            $reliabilitas['double_annotated']
        ));

        if ($reliabilitas['cohen_kappa'] < 0.60) {
            $this->warn(
                'Kappa di bawah 0,60 (kesepakatan "Sedang" atau lebih rendah). Batas atas kinerja model '.
                'ikut rendah - pertimbangkan menulis ulang pedoman anotasi untuk kasus yang paling sering '.
                'berselisih sebelum melanjutkan ke evaluasi.'
            );
        }
    }

    private function namaBawaan(string $path1): string
    {
        return dirname($path1).DIRECTORY_SEPARATOR.'golden-gabungan-'.now()->format('Y-m-d_His').'.csv';
    }

    private function tulisCsv(string $path, array $baris): void
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Tidak bisa menulis ke: {$path}");
        }

        // BOM supaya Excel/Sheets Indonesia membuka UTF-8 dengan benar,
        // konsisten dengan ekspor /anotasi sendiri.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['id', 'text', 'label', 'label_2', 'aspects', 'segment', 'note']);

        foreach ($baris as $r) {
            fputcsv($handle, [
                $r['id'], $r['text'], $r['label'], $r['label_2'], $r['aspects'], $r['segment'], $r['note'],
            ]);
        }

        fclose($handle);
    }
}
