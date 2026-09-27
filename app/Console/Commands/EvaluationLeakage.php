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
        $this->newLine();

        // ── Angka yang menentukan: hanya item terkoreksi yang pernah dilatih ──
        $this->line('<options=bold>KEBOCORAN NYATA KE DATA LATIH</>');
        $this->line(sprintf(
            '  Baris golden yang pernah masuk muatan pelatihan: %d dari %d (%.2f%%)',
            $laporan['corrected_golden_affected'],
            $laporan['total_golden'],
            $laporan['total_golden'] > 0
                ? $laporan['corrected_golden_affected'] / $laporan['total_golden'] * 100
                : 0.0
        ));
        $this->line('  Baris training_items terkoreksi yang bertabrakan: '.$laporan['corrected_overlap']);

        if ($laporan['corrected_overlap'] === 0) {
            $this->info('  => Nihil. Tidak satu pun baris uji pernah dilatihkan; angka hold-out sah dikutip.');
        } else {
            $this->error('  => ADA KEBOCORAN. Baris tersebut ditahan RetrainingService, tetapi periksa riwayat pelatihan sebelumnya.');
        }

        $this->newLine();

        // ── Konteks: kolam kandidat, bukan muatan pelatihan ──────────────────
        $this->line('<options=bold>KONTEKS: KOLAM KANDIDAT</>');
        $this->line(sprintf(
            '  Baris golden yang terdaftar di training_items: %d dari %d (%.2f%%)',
            $laporan['golden_affected'],
            $laporan['total_golden'],
            $laporan['total_golden'] > 0
                ? $laporan['golden_affected'] / $laporan['total_golden'] * 100
                : 0.0
        ));
        $this->line('  Jumlah baris training_items yang bertabrakan: '.$laporan['overlap']);
        $this->comment(
            '  Angka ini WAJAR mendekati 100% dan BUKAN kebocoran: training_items memuat setiap '.
            'baris hasil analisis, sedangkan golden dataset memang disampel dari analisis yang sama. '.
            'Muatan pelatihan hanya diambil dari baris berstatus is_corrected, dan baris golden '.
            'disaring lagi oleh RetrainingService::correctedItems() sebelum pelatihan. Jangan kutip '.
            'angka ini sebagai angka kebocoran di naskah.'
        );

        if ($laporan['overlap'] === 0) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Contoh baris yang terdaftar di kedua sisi (dipotong 120 karakter):');

        foreach ($laporan['examples'] as $contoh) {
            $this->line('  - '.$contoh);
        }

        return self::SUCCESS;
    }
}
