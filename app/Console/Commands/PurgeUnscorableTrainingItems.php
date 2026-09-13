<?php

namespace App\Console\Commands;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Support\TextScorability;
use Illuminate\Console\Command;

/**
 * Buang baris koreksi yang tidak bisa dinilai.
 *
 * `TrainingItemService` sudah menolaknya sejak awal, tetapi baris yang
 * terlanjur dibuat sebelum penolakan itu ada tetap tertinggal - dan pada
 * baris lama `predicted_method` bernilai NULL, sehingga penyaringan di halaman
 * koreksi meloloskannya. Membuangnya di sini membuat seluruh hitungan kembali
 * konsisten tanpa perlu menghapus dan menyusun ulang analisisnya.
 *
 * Baris yang SUDAH dikoreksi manusia tidak pernah disentuh: apa pun isinya,
 * seseorang sudah menghabiskan waktu untuknya.
 */
class PurgeUnscorableTrainingItems extends Command
{
    protected $signature = 'training:purge-unscorable
        {--analysis= : Batasi ke satu analisis}
        {--apply : Benar-benar hapus; tanpa ini hanya melaporkan}';

    protected $description = 'Buang baris koreksi yang isinya emotikon/tanda baca saja';

    public function handle(): int
    {
        $diisi = $this->backfillMethod();

        if ($diisi > 0) {
            $this->info("{$diisi} baris diisi `predicted_method` dari hasil analisis tersimpan.");
        }

        $kueri = TrainingItem::query()
            ->when($this->option('analysis'), fn ($q) => $q->where('text_analysis_id', $this->option('analysis')));

        $sasaran = [];
        $terlindungi = 0;

        $kueri->select(['id', 'text_analysis_id', 'text_content', 'predicted_method', 'is_corrected'])
            ->chunkById(500, function ($potongan) use (&$sasaran, &$terlindungi) {
                foreach ($potongan as $item) {
                    if (! TextScorability::isUnscorable($item->text_content, $item->predicted_method)) {
                        continue;
                    }

                    if ($item->is_corrected) {
                        $terlindungi++;

                        continue;
                    }

                    $sasaran[] = $item->id;
                }
            });

        if (empty($sasaran)) {
            $this->info('Tidak ada baris tidak-dinilai yang perlu dibuang.');

            if ($terlindungi > 0) {
                $this->comment("{$terlindungi} baris tidak dinilai sudah pernah dikoreksi manusia dan sengaja dibiarkan.");
            }

            return self::SUCCESS;
        }

        $contoh = TrainingItem::whereIn('id', array_slice($sasaran, 0, 5))->pluck('text_content');

        $this->warn(count($sasaran).' baris tidak bisa dinilai (emotikon/tanda baca saja).');
        foreach ($contoh as $teks) {
            $this->line('  - '.mb_substr((string) $teks, 0, 40));
        }

        if ($terlindungi > 0) {
            $this->comment("{$terlindungi} baris serupa sudah pernah dikoreksi manusia dan tidak akan disentuh.");
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Jalankan ulang dengan --apply untuk benar-benar menghapus.');

            return self::SUCCESS;
        }

        $dihapus = 0;
        foreach (array_chunk($sasaran, 500) as $potongan) {
            $dihapus += TrainingItem::whereIn('id', $potongan)->delete();
        }

        $this->info("{$dihapus} baris dihapus.");

        return self::SUCCESS;
    }

    /**
     * Isi `predicted_method` untuk baris yang dibuat sebelum kolom itu ada.
     *
     * Perlu karena heuristik teks saja tidak menangkap semuanya: pada analisis
     * pertama studi kasus ada 182 prediksi bermetode `empty` sementara hanya
     * 175 yang teksnya benar-benar tanpa huruf. Tujuh sisanya punya huruf tetapi
     * habis setelah pembersihan - hanya layanan yang tahu itu, dan
     * pengetahuannya ada di `method`.
     */
    private function backfillMethod(): int
    {
        $analyses = TextAnalysis::with('result')
            ->when($this->option('analysis'), fn ($q) => $q->where('id', $this->option('analysis')))
            ->whereHas('trainingItems', fn ($q) => $q->whereNull('predicted_method'))
            ->get();

        $total = 0;

        foreach ($analyses as $analysis) {
            $peta = [];

            foreach ($analysis->result?->predictions ?? [] as $prediksi) {
                $teks = $prediksi['text'] ?? null;
                $metode = $prediksi['method'] ?? null;

                if (filled($teks) && filled($metode)) {
                    $peta[$teks] = $metode;
                }
            }

            if (empty($peta)) {
                continue;
            }

            // Dikelompokkan per metode supaya jumlah query-nya sebanyak jenis
            // metode, bukan sebanyak baris.
            $perMetode = [];
            foreach ($peta as $teks => $metode) {
                $perMetode[$metode][] = $teks;
            }

            foreach ($perMetode as $metode => $daftarTeks) {
                foreach (array_chunk($daftarTeks, 500) as $potongan) {
                    $total += TrainingItem::where('text_analysis_id', $analysis->id)
                        ->whereNull('predicted_method')
                        ->whereIn('text_content', $potongan)
                        ->update(['predicted_method' => $metode]);
                }
            }
        }

        return $total;
    }
}
