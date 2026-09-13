<?php

namespace App\Console\Commands;

use App\Models\EvaluationDataset;
use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Console\Command;

/**
 * Memeriksa apakah ada baris golden dataset yang juga ada di data latih.
 *
 * Penjaganya sudah otomatis di RetrainingService, tetapi pemeriksaan yang
 * bisa dijalankan sendiri tetap perlu: naskah harus bisa menyatakan angka
 * kebocorannya, bukan sekadar mengklaim bahwa mekanismenya ada.
 */
class EvaluationLeakage extends Command
{
    protected $signature = 'evaluation:leakage {--dataset= : Periksa satu dataset saja}';

    protected $description = 'Periksa tumpang tindih golden dataset dengan data latih';

    public function handle(GoldenDatasetService $golden): int
    {
        $dataset = $this->option('dataset')
            ? EvaluationDataset::find($this->option('dataset'))
            : null;

        if ($this->option('dataset') && ! $dataset) {
            $this->error('Golden dataset tidak ditemukan.');

            return self::FAILURE;
        }

        $laporan = $golden->leakageReport($dataset);

        if ($laporan['total_golden'] === 0) {
            $this->warn('Belum ada golden dataset beku bertipe test. Tidak ada yang perlu diperiksa.');

            return self::SUCCESS;
        }

        $this->info('Baris golden diperiksa: '.$laporan['total_golden']);
        $this->info('Bertabrakan dengan training_items: '.$laporan['overlap']);
        $this->info('Di antaranya sudah dikoreksi manusia: '.$laporan['corrected_overlap']);

        if ($laporan['overlap'] === 0) {
            $this->info('Tidak ada kebocoran. Angka hold-out sah untuk dikutip.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Baris berikut ada di kedua sisi (dipotong 120 karakter):');

        foreach ($laporan['examples'] as $contoh) {
            $this->line('  - '.$contoh);
        }

        $this->newLine();
        $this->comment(
            'Baris ini otomatis disingkirkan dari data latih, jadi hold-out tetap sah. '.
            'Yang berkurang adalah jumlah sampel pelatihan, bukan keabsahan evaluasi.'
        );

        return self::SUCCESS;
    }
}
