<?php

namespace App\Console\Commands;

use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Memecah satu kumpulan teks beranotasi menjadi test set (dibekukan) dan
 * sisa yang boleh dipakai melatih.
 *
 * Dipakai bila anotasi dibuat sekaligus dalam satu berkas. Pemecahannya
 * berstrata dan disemai, jadi bisa diulang persis - syarat supaya angka
 * evaluasi di naskah bisa dipertanggungjawabkan.
 */
class EvaluationSplit extends Command
{
    protected $signature = 'evaluation:split
        {file : Berkas beranotasi yang akan dipecah}
        {--test-ratio=0.2 : Porsi yang menjadi test set}
        {--seed=42 : Semai acak, dicatat supaya bisa diulang}
        {--name= : Nama golden dataset yang dibuat}
        {--out= : Tempat menyimpan sisa (train) sebagai CSV}
        {--no-segment-strata : Stratifikasi hanya pada label, abaikan kolom segmen}
        {--freeze : Bekukan test set setelah dibuat}';

    protected $description = 'Pecah berkas beranotasi menjadi hold-out test set dan sisa data latih';

    public function handle(GoldenDatasetService $golden): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        try {
            $dibaca = $golden->readFile($path);
            $pecah = $golden->stratifiedSplit(
                $dibaca['rows'],
                (float) $this->option('test-ratio'),
                (int) $this->option('seed'),
                ! $this->option('no-segment-strata'),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($dibaca['errors'] as $galat) {
            $this->warn($galat);
        }

        $this->info(sprintf(
            'Dipecah dengan semai %d: %d baris uji, %d baris latih, dari %d strata.',
            $pecah['seed'],
            count($pecah['test']),
            count($pecah['train']),
            count($pecah['strata'])
        ));

        if (empty($pecah['test'])) {
            $this->error('Test set kosong. Naikkan --test-ratio atau tambah data.');

            return self::FAILURE;
        }

        $dataset = $golden->store($pecah['test'], [
            'name' => $this->option('name') ?: 'Hold-out '.now()->format('Y-m-d H:i'),
            'description' => sprintf(
                'Dipecah dari %s dengan rasio %s dan semai %d (berstrata%s).',
                basename($path),
                $this->option('test-ratio'),
                $pecah['seed'],
                $this->option('no-segment-strata') ? ' pada label' : ' pada label dan segmen'
            ),
            'split' => 'test',
            'source' => 'split',
        ]);

        if ($this->option('freeze')) {
            $golden->freeze($dataset);
            $this->info('Test set dibekukan.');
        }

        $this->info("Golden dataset #{$dataset->id} dibuat dengan {$dataset->fresh()->item_count} baris.");

        if ($out = $this->option('out')) {
            $this->tulisCsv($out, $pecah['train']);
            $this->info('Sisa data latih ditulis ke '.$out);
        } else {
            $this->comment('Sisa data latih tidak ditulis. Pakai --out=path.csv bila ingin menyimpannya.');
        }

        return self::SUCCESS;
    }

    private function tulisCsv(string $path, array $baris): void
    {
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['text', 'label', 'aspects', 'segment']);

        foreach ($baris as $satu) {
            fputcsv($handle, [
                $satu['text'],
                $satu['gold_sentiment'],
                implode(';', (array) ($satu['gold_aspects'] ?? [])),
                $satu['segment'] ?? '',
            ]);
        }

        fclose($handle);
    }
}
