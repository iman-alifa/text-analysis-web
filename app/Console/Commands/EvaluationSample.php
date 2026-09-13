<?php

namespace App\Console\Commands;

use App\Services\Evaluation\ClassificationMetrics;
use App\Services\Evaluation\GoldenSamplingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ambil sampel dari korpus yang sudah ada di sistem untuk dianotasi menjadi
 * golden dataset.
 *
 * Sengaja tidak menyediakan pengambilan berbasis keyakinan model. Lihat
 * GoldenSamplingService untuk alasannya.
 */
class EvaluationSample extends Command
{
    protected $signature = 'evaluation:sample
        {--analysis=* : ID analisis sumber (boleh diulang)}
        {--size=300 : Jumlah baris yang diambil}
        {--percent= : Alternatif --size: persen dari kolam yang layak dianotasi, mis. 10}
        {--seed=42 : Semai acak, dicatat pada rencana pengambilan}
        {--stratify= : Nama kolom pemisah untuk stratifikasi, mis. lembaga}
        {--min-length=20 : Panjang minimal teks}
        {--min-per-stratum=0 : Jatah minimal tiap strata}
        {--include-corrected : Jangan kecualikan baris yang sudah dikoreksi (tidak disarankan)}
        {--name= : Nama golden dataset yang dibuat}
        {--out= : Tulis berkas anotasi kosong ke sini}
        {--with-prediction : Sertakan kolom prediksi model pada berkas anotasi}
        {--dry-run : Tampilkan rencana saja, tidak menyimpan}';

    protected $description = 'Ambil sampel acak berstrata untuk dianotasi menjadi golden dataset';

    public function handle(GoldenSamplingService $sampling, ClassificationMetrics $metrics): int
    {
        $sumber = array_map('intval', (array) $this->option('analysis'));

        if (empty($sumber)) {
            $this->error('Pilih minimal satu analisis sumber lewat --analysis=ID.');
            $this->newLine();
            $this->tampilkanSumber($sampling);

            return self::FAILURE;
        }

        try {
            $hasil = $sampling->sample([
                'analysis_ids' => $sumber,
                'size' => (int) $this->option('size'),
                'percent' => $this->option('percent') !== null ? (float) $this->option('percent') : null,
                'seed' => (int) $this->option('seed'),
                'stratify_by' => $this->option('stratify') ?: null,
                'min_length' => (int) $this->option('min-length'),
                'min_per_stratum' => (int) $this->option('min-per-stratum'),
                'exclude_corrected' => ! $this->option('include-corrected'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->tampilkanRencana($hasil['plan'], $metrics);

        if ($this->option('dry-run')) {
            $this->comment('Dry run: tidak ada yang disimpan.');

            return self::SUCCESS;
        }

        $dataset = $sampling->store($hasil['rows'], $hasil['plan'], [
            'name' => $this->option('name') ?: 'Sampel golden '.now()->format('Y-m-d H:i'),
            'description' => 'Sampel acak berstrata, semai '.$hasil['plan']['seed'].
                '. Menunggu anotasi manusia.',
            'split' => 'test',
        ]);

        $this->info("Golden dataset #{$dataset->id} dibuat dengan {$dataset->item_count} baris, semuanya BELUM berlabel.");

        $out = $this->option('out') ?: 'anotasi-golden-'.$dataset->id.'.csv';
        $this->tulisBerkasAnotasi($sampling, $dataset, $out, (bool) $this->option('with-prediction'));

        $this->newLine();
        $this->line('Langkah berikutnya:');
        $this->line('  1. Anotasi berkas '.$out.' - isi kolom `label`, dan `label_2` untuk sebagian baris.');
        $this->line('  2. Jangan menyunting kolom `id`; itu yang memasangkan baris kembali.');
        $this->line('  3. Unggah kembali lewat /admin/evaluation, lalu bekukan datasetnya.');
        $this->line('  4. Baru setelah itu jalankan evaluation:run untuk baseline.');

        return self::SUCCESS;
    }

    private function tampilkanSumber(GoldenSamplingService $sampling): void
    {
        $baris = [];

        foreach ($sampling->availableSources() as $sumber) {
            $baris[] = [
                $sumber['id'],
                mb_substr($sumber['title'], 0, 40),
                $sumber['type'],
                $sumber['text_count'],
                implode(', ', $sumber['segment_columns']) ?: '-',
            ];
        }

        if (empty($baris)) {
            $this->warn('Belum ada analisis yang bisa dijadikan sumber sampel.');

            return;
        }

        $this->line('Analisis yang tersedia:');
        $this->table(['ID', 'Judul', 'Jenis', 'Teks', 'Kolom pemisah'], $baris);
    }

    private function tampilkanRencana(array $plan, ClassificationMetrics $metrics): void
    {
        $this->newLine();
        $this->table(['Rencana pengambilan', 'Nilai'], [
            ['Metode', $plan['method']],
            ['Kandidat setelah penyaringan', $plan['candidates']],
            ['Diminta', $plan['requested_size']
                .($plan['requested_percent'] !== null
                    ? ' (dari '.$plan['requested_percent'].'% kolam layak)'
                    : '')],
            ['Terambil', $plan['sampled']],
            ['Semai', $plan['seed']],
            ['Stratifikasi', $plan['stratify_by'] ?? 'tidak (per analisis)'],
            ['Panjang minimal', $plan['min_length'].' karakter'],
            ['Kecualikan yang sudah dikoreksi', $plan['exclude_corrected'] ? 'ya' : 'TIDAK'],
        ]);

        $this->table(['Ditolak karena', 'Jumlah'], [
            ['Tidak bisa dinilai (emotikon/tanda baca saja)', $plan['rejected']['tidak_dinilai'] ?? 0],
            ['Terlalu pendek', $plan['rejected']['terlalu_pendek']],
            ['Duplikat', $plan['rejected']['duplikat']],
            ['Sudah ada di golden dataset lain', $plan['rejected']['sudah_di_golden']],
            ['Sudah dikoreksi (data latih)', $plan['rejected']['sudah_dikoreksi']],
        ]);

        $strata = [];
        foreach ($plan['strata'] as $nama => $angka) {
            $strata[] = [mb_substr((string) $nama, 0, 40), $angka['tersedia'], $angka['diambil']];
        }
        $this->table(['Strata', 'Tersedia', 'Diambil'], $strata);

        // Margin dihitung pada akurasi 85% - taksiran wajar untuk model ini.
        // Angkanya membantu memutuskan ukuran sampel sebelum anotasi dimulai,
        // saat menambah baris masih murah.
        $n = $plan['sampled'];
        $ci = $metrics->wilson((int) round(0.85 * $n), $n);
        $margin = round(($ci['high'] - $ci['low']) / 2, 1);

        $this->info("Dengan {$n} baris, margin selang kepercayaan 95% sekitar +-{$margin} poin (pada akurasi 85%).");

        if ($n < 200) {
            $this->warn('Di bawah 200 baris, selangnya terlalu lebar untuk mendeteksi perbaikan beberapa poin antar iterasi.');
        }

        if (! $plan['exclude_corrected']) {
            $this->warn('Baris yang sudah dikoreksi IKUT terambil. Baris itu data latih; memasukkannya ke test set adalah kebocoran.');
        }

        if ($plan['min_per_stratum'] > 0) {
            $this->warn(
                'Jatah minimal per strata membuat proporsinya tidak lagi persis mengikuti korpus. '.
                'Angka keseluruhan lalu menaksir rerata antar-strata, bukan rerata korpus - sebutkan ini di naskah.'
            );
        }
    }

    private function tulisBerkasAnotasi(GoldenSamplingService $sampling, $dataset, string $path, bool $denganPrediksi): void
    {
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $sampling->annotationHeader($denganPrediksi));

        foreach ($sampling->annotationRows($dataset, $denganPrediksi) as $baris) {
            fputcsv($handle, $baris);
        }

        fclose($handle);

        $this->info('Berkas anotasi ditulis ke '.$path);

        if ($denganPrediksi) {
            $this->comment(
                'Berkas memuat prediksi model. Aplikasi anotasi (public/anotasi) menyembunyikannya '
                .'sampai anotator memilih labelnya sendiri - membukanya lebih awal membuat anotator '
                .'cenderung menyetujui model, dan akurasi terukur naik tanpa modelnya berubah.'
            );
        } else {
            $this->comment('Berkas ini tidak memuat prediksi model. Pakai --with-prediction bila memang diinginkan.');
        }
    }
}
