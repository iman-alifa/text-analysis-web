<?php

namespace App\Console\Commands;

use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Console\Command;
use Throwable;

class EvaluationImport extends Command
{
    protected $signature = 'evaluation:import
        {file : Berkas CSV/XLSX berisi kolom teks dan label}
        {--name= : Nama golden dataset}
        {--description= : Keterangan singkat}
        {--split=test : test atau dev}
        {--freeze : Langsung bekukan setelah impor}
        {--dry-run : Hanya periksa berkas, tidak menyimpan}';

    protected $description = 'Impor golden dataset (hold-out test) dari berkas beranotasi manusia';

    public function handle(GoldenDatasetService $golden): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        try {
            $dibaca = $golden->readFile($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $baris = $dibaca['rows'];

        $this->info('Kolom terbaca: '.implode(', ', $dibaca['columns']));
        $this->info('Baris sah: '.count($baris));

        foreach ($dibaca['errors'] as $galat) {
            $this->warn($galat);
        }

        if (empty($baris)) {
            $this->error('Tidak ada baris yang bisa diimpor.');

            return self::FAILURE;
        }

        $this->tampilkanDistribusi($baris);

        if ($this->option('dry-run')) {
            $this->comment('Dry run: tidak ada yang disimpan.');

            return self::SUCCESS;
        }

        $nama = $this->option('name') ?: 'Golden '.now()->format('Y-m-d H:i');

        $dataset = $golden->store($baris, [
            'name' => $nama,
            'description' => $this->option('description'),
            'split' => $this->option('split'),
            'source' => 'import',
        ]);

        if ($this->option('freeze')) {
            $golden->freeze($dataset);
            $this->info('Dataset dibekukan.');
        } else {
            $this->comment('Dataset BELUM dibekukan. Jalankan evaluasi hanya setelah dibekukan.');
        }

        $dataset->refresh();

        if ($reliabilitas = $dataset->annotation) {
            $this->info(sprintf(
                'Reliabilitas anotasi: kappa %.3f (%s) atas %d baris beranotasi ganda.',
                $reliabilitas['cohen_kappa'],
                $reliabilitas['label'],
                $reliabilitas['double_annotated']
            ));
        }

        $bocor = $golden->leakageReport($dataset);

        if ($bocor['overlap'] > 0) {
            $this->warn(sprintf(
                '%d baris golden juga ada di training_items (%d di antaranya sudah dikoreksi). Baris itu akan otomatis disingkirkan dari data latih.',
                $bocor['overlap'],
                $bocor['corrected_overlap']
            ));
        }

        $this->info("Golden dataset #{$dataset->id} \"{$dataset->name}\" tersimpan dengan {$dataset->item_count} baris.");

        return self::SUCCESS;
    }

    private function tampilkanDistribusi(array $baris): void
    {
        $hitung = [];

        foreach ($baris as $satu) {
            $hitung[$satu['gold_sentiment']] = ($hitung[$satu['gold_sentiment']] ?? 0) + 1;
        }

        ksort($hitung);
        $total = count($baris);
        $rows = [];

        foreach ($hitung as $label => $jumlah) {
            $rows[] = [$label, $jumlah, round($jumlah / $total * 100, 1).'%'];
        }

        $this->table(['Label', 'Jumlah', 'Porsi'], $rows);

        $terbanyak = max($hitung);
        $tersedikit = min($hitung);

        if ($tersedikit > 0 && $terbanyak / $tersedikit >= 5) {
            $this->warn(sprintf(
                'Rasio ketimpangan %.1f:1. Laporkan macro-F1, bukan hanya akurasi - pada distribusi setimpang ini akurasi bisa tinggi tanpa model memahami kelas minoritas.',
                $terbanyak / $tersedikit
            ));
        }

        if ($total < 200) {
            $this->warn("Hanya {$total} baris. Selang kepercayaan akan lebar; pertimbangkan menambah anotasi sampai sekitar 300-400 baris.");
        }
    }
}
