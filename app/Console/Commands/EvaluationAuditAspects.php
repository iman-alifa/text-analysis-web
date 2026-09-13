<?php

namespace App\Console\Commands;

use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use App\Services\NLPApiService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Periksa KONSISTENSI anotasi aspek pada golden dataset.
 *
 * Yang dicari: istilah yang anotator sendiri sudah terima sebagai aspek di
 * baris lain, tetapi tidak dilabeli pada baris yang jelas memuatnya.
 *
 * Prinsipnya sama dengan yang sudah dipakai `build_aspect_dataset.py` di sisi
 * NLP: sekali sebuah istilah diterima sebagai aspek, SETIAP kemunculannya
 * dilabeli. Tanpa itu supervisinya saling bertentangan - model dihukum karena
 * menemukan istilah yang anotatornya sendiri anggap aspek. Terukur pada golden
 * dataset studi kasus: `rakyat` dilabeli 12 kali dan terlewat 17 kali,
 * `negara` 4 berbanding 17.
 *
 * Perintah ini TIDAK mengarang label baru. Ia hanya menyebarkan keputusan yang
 * sudah diambil anotator, dan bawaannya hanya menampilkan usulan - tidak
 * mengubah apa pun tanpa `--apply`.
 */
class EvaluationAuditAspects extends Command
{
    protected $signature = 'evaluation:audit-aspects
                            {dataset : ID golden dataset}
                            {--apply : Terapkan usulan (tanpa ini hanya menampilkan)}
                            {--min-support=2 : Istilah harus sudah dilabeli sekian kali untuk dianggap diterima}
                            {--exclude=* : Istilah yang tidak boleh disebarkan}
                            {--only-predicted : Hanya tambahkan pada baris yang aspeknya memang diprediksi model}
                            {--restore= : Pulihkan label dari berkas anotasi asli, lalu berhenti}';

    protected $description = 'Periksa konsistensi label aspek pada golden dataset dan usulkan perbaikannya';

    /**
     * Kelas kesalahan sistematis yang tidak boleh disebarkan otomatis.
     *
     * Diturunkan dari penolakan yang sudah dipakai `annotate_aspects.py`: kata
     * opini, kata kerja, dan penanda umum bukan aspek - sekalipun anotator
     * sempat melabelinya. Menyebarkannya akan memperbanyak kesalahan, bukan
     * memperbaiki konsistensi.
     */
    private const TOLAK = [
        'sukses', 'bagus', 'baik', 'buruk', 'jelek', 'hebat', 'mantap',
        'benar', 'salah', 'setuju', 'suka', 'bangga', 'malu', 'sedih',
        'senang', 'kecewa', 'marah', 'takut', 'hormat', 'semangat',
        'indonesia', 'negeri', 'dunia', 'orang', 'kali', 'saat', 'waktu',
    ];

    public function handle(NLPApiService $api): int
    {
        $dataset = EvaluationDataset::find($this->argument('dataset'));

        if (! $dataset) {
            $this->error('Golden dataset tidak ditemukan.');

            return self::FAILURE;
        }

        if ($berkas = $this->option('restore')) {
            return $this->pulihkan($dataset, $berkas);
        }

        $minSupport = max(1, (int) $this->option('min-support'));
        $tolak = array_merge(self::TOLAK, array_map('mb_strtolower', (array) $this->option('exclude')));

        $items = $dataset->items()->orderBy('id')->get();
        $this->info("Golden dataset #{$dataset->id} — {$items->count()} baris");

        // ── 1. Kosakata aspek yang sudah DITERIMA anotator ──────────────────
        $diterima = [];
        foreach ($items as $item) {
            foreach ((array) ($item->gold_aspects_normalized ?: []) as $g) {
                $g = mb_strtolower(trim((string) $g));
                // Hanya istilah satu kata: frasa tidak bisa dicari lewat
                // pencocokan kata per kata tanpa memunculkan salah tebak.
                if ($g !== '' && ! str_contains($g, ' ')) {
                    $diterima[$g] = ($diterima[$g] ?? 0) + 1;
                }
            }
        }

        $mantap = array_keys(array_filter(
            $diterima,
            fn ($n, $g) => $n >= $minSupport && ! in_array($g, $tolak, true),
            ARRAY_FILTER_USE_BOTH
        ));

        if (empty($mantap)) {
            $this->warn('Belum ada istilah yang cukup sering dilabeli untuk dijadikan acuan.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d istilah dipakai >= %d kali dan dijadikan acuan (%d ditolak sebagai kata opini/umum).',
            count($mantap), $minSupport, count($diterima) - count($mantap)
        ));

        // ── 2. Bentuk dasar tiap kata pada tiap teks, lewat stemmer layanan ─
        $this->line('Menormalkan kata pada teks lewat /api/normalize/aspects...');

        $semuaKata = [];
        foreach ($items as $item) {
            foreach ($this->kataPada($item->text) as $kata) {
                $semuaKata[$kata] = true;
            }
        }

        try {
            $peta = $this->normalkan($api, array_keys($semuaKata));
        } catch (Throwable $e) {
            $this->error('Gagal menormalkan: '.$e->getMessage());
            $this->line('Pastikan nlp-api-service berjalan.');

            return self::FAILURE;
        }

        // ── 3. Cari kemunculan yang belum dilabeli ──────────────────────────
        $acuan = array_flip($mantap);
        $usulan = [];

        // Mode --only-predicted: usulan dibatasi pada aspek yang MEMANG
        // diprediksi model pada baris itu.
        //
        // Ini menjawab keberatan yang sah - anotator mungkin sengaja tidak
        // melabeli sebuah kemunculan, dan menyebarkannya ke semua baris
        // menganggap setiap kelalaian sebagai kekeliruan.
        //
        // TETAPI cara ini punya bias yang harus disadari: setiap label yang
        // ditambahkan otomatis menjadi true positive, sehingga presisi dan
        // recall naik tanpa satu pun false negative baru. Label emasnya jadi
        // sebagian ditentukan oleh keluaran model, dan kata yang sama bisa
        // dilabeli di satu baris tetapi tidak di baris lain semata karena
        // modelnya kebetulan menemukannya. Sebutkan ini di naskah.
        $diprediksi = null;
        if ($this->option('only-predicted')) {
            $diprediksi = $this->prediksiTerakhir($dataset);

            if ($diprediksi === null) {
                $this->error('Belum ada run selesai yang menyimpan prediksi aspek.');
                $this->line('Jalankan `php artisan evaluation:run '.$dataset->id.'` lebih dulu.');

                return self::FAILURE;
            }

            $this->line('Mode --only-predicted: usulan dibatasi pada aspek yang diprediksi model.');
        }

        foreach ($items as $item) {
            $sudah = array_flip(array_map(
                fn ($g) => mb_strtolower(trim((string) $g)),
                (array) ($item->gold_aspects_normalized ?: [])
            ));

            foreach ($this->kataPada($item->text) as $kata) {
                $dasar = $peta[$kata] ?? null;
                if (! $dasar || ! isset($acuan[$dasar]) || isset($sudah[$dasar])) {
                    continue;
                }

                if ($diprediksi !== null && ! isset($diprediksi[$item->id][$dasar])) {
                    continue;
                }

                $usulan[$item->id][$dasar] = $kata;
                $sudah[$dasar] = true;
            }
        }

        $total = array_sum(array_map('count', $usulan));

        if ($total === 0) {
            $this->info('Anotasi sudah konsisten; tidak ada usulan.');

            return self::SUCCESS;
        }

        $perIstilah = [];
        foreach ($usulan as $daftar) {
            foreach ($daftar as $dasar => $_) {
                $perIstilah[$dasar] = ($perIstilah[$dasar] ?? 0) + 1;
            }
        }
        arsort($perIstilah);

        $this->newLine();
        $this->table(
            ['istilah', 'sudah dilabeli', 'usulan tambahan'],
            array_map(
                fn ($g, $n) => [$g, $diterima[$g] ?? 0, $n],
                array_keys($perIstilah),
                $perIstilah
            )
        );
        $this->line("Total {$total} label pada ".count($usulan).' baris.');

        if (! $this->option('apply')) {
            $this->newLine();
            $this->warn('Belum diterapkan. Tinjau tabel di atas, lalu ulangi dengan --apply.');
            $this->line('Istilah yang tidak Anda setujui: tambahkan --exclude=nama_istilah.');

            return self::SUCCESS;
        }

        if ($dataset->isFrozen() && $dataset->runs()->exists()) {
            $this->newLine();
            $this->warn(
                'Dataset ini beku dan sudah punya riwayat run. Mengubah labelnya membuat '.
                'run lama TIDAK sebanding dengan run baru.'
            );
            $this->line('Jalankan ulang seluruh iterasi setelah perubahan ini, dan sebutkan di naskah.');

            if (! $this->confirm('Lanjutkan?', false)) {
                return self::SUCCESS;
            }
        }

        foreach ($usulan as $itemId => $daftar) {
            $item = EvaluationItem::find($itemId);
            if (! $item) {
                continue;
            }

            // Bentuk permukaan ditambahkan ke gold_aspects (yang dibaca manusia),
            // bentuk dasarnya ke gold_aspects_normalized (yang dibandingkan).
            $item->update([
                'gold_aspects' => array_values(array_unique(array_merge(
                    (array) ($item->gold_aspects ?: []), array_values($daftar)
                ))),
                'gold_aspects_normalized' => array_values(array_unique(array_merge(
                    (array) ($item->gold_aspects_normalized ?: []), array_keys($daftar)
                ))),
            ]);
        }

        $this->info("Diterapkan: {$total} label pada ".count($usulan).' baris.');
        $this->line('Jalankan `php artisan evaluation:run '.$dataset->id.'` untuk mengukur ulang.');

        return self::SUCCESS;
    }

    /**
     * Aspek yang diprediksi model per item, dari run selesai paling akhir.
     *
     * @return array<int, array<string, bool>>|null
     */
    private function prediksiTerakhir(EvaluationDataset $dataset): ?array
    {
        $run = $dataset->runs()->where('status', 'completed')->latest('id')->first();

        if (! $run) {
            return null;
        }

        $peta = [];
        foreach ($run->items as $baris) {
            foreach ((array) ($baris->predicted_aspects ?: []) as $aspek) {
                $nama = mb_strtolower(trim((string) $aspek));
                if ($nama !== '') {
                    $peta[$baris->evaluation_item_id][$nama] = true;
                }
            }
        }

        return $peta;
    }

    /**
     * Kembalikan label aspek ke keadaan berkas anotasi asli.
     *
     * Ada karena perintah ini menimpa label tanpa menyimpan apa yang ditambah -
     * kelalaian rancangan yang membuat hasil audit tidak bisa dibatalkan.
     * Sumber kebenarannya berkas anotasi manusia, bukan basis data.
     */
    private function pulihkan(EvaluationDataset $dataset, string $berkas): int
    {
        if (! is_file($berkas)) {
            $this->error("Berkas tidak ditemukan: {$berkas}");

            return self::FAILURE;
        }

        $fh = fopen($berkas, 'r');
        $header = fgetcsv($fh);
        $kolom = array_flip(array_map(fn ($k) => trim(strtolower((string) $k)), $header ?: []));

        if (! isset($kolom['text'], $kolom['aspects'])) {
            fclose($fh);
            $this->error('Berkas harus punya kolom `text` dan `aspects`.');

            return self::FAILURE;
        }

        $asli = [];
        while (($baris = fgetcsv($fh)) !== false) {
            if (count($baris) < 2) {
                continue;
            }
            $teks = (string) ($baris[$kolom['text']] ?? '');
            $aspek = trim((string) ($baris[$kolom['aspects']] ?? ''));
            $daftar = ($aspek === '' || $aspek === '-')
                ? []
                : array_values(array_filter(array_map('trim', explode(';', $aspek))));
            $asli[\App\Support\TextFingerprint::hash($teks)] = $daftar;
        }
        fclose($fh);

        $diubah = 0;
        $tidakKetemu = 0;

        foreach ($dataset->items as $item) {
            if (! array_key_exists($item->text_hash, $asli)) {
                $tidakKetemu++;

                continue;
            }

            if ((array) $item->gold_aspects !== $asli[$item->text_hash]) {
                // Bentuk dasar dikosongkan supaya evaluation:normalize-aspects
                // mengisinya ulang dari label yang benar.
                $item->update([
                    'gold_aspects' => $asli[$item->text_hash],
                    'gold_aspects_normalized' => null,
                ]);
                $diubah++;
            }
        }

        $this->info("Dipulihkan: {$diubah} baris dikembalikan ke label asli.");
        if ($tidakKetemu) {
            $this->warn("{$tidakKetemu} baris tidak ada padanannya di berkas.");
        }
        $this->line('Jalankan `php artisan evaluation:normalize-aspects` untuk mengisi bentuk dasarnya.');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function kataPada(string $teks): array
    {
        preg_match_all('/\w+/u', mb_strtolower($teks), $cocok);

        return array_values(array_unique(array_filter(
            $cocok[0] ?? [],
            fn ($k) => mb_strlen($k) > 2
        )));
    }

    /**
     * @param  array<int, string>  $kata
     * @return array<string, string>
     */
    private function normalkan(NLPApiService $api, array $kata): array
    {
        $peta = [];
        foreach (array_chunk($kata, 2000) as $potongan) {
            $hasil = $api->normalizeAspectNames($potongan);

            if ($hasil === null) {
                throw new \RuntimeException('layanan menolak permintaan normalisasi');
            }

            foreach ($hasil as $asli => $dasar) {
                $peta[$asli] = $dasar;
            }
        }

        return $peta;
    }
}
