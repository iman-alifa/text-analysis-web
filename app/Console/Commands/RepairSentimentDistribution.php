<?php

namespace App\Console\Commands;

use App\Models\AnalysisResult;
use Illuminate\Console\Command;

/**
 * Analisis lama (>batch_size teks) menyimpan sentiment_distribution hasil
 * penjumlahan persentase antar-batch, sehingga totalnya bisa ratusan persen.
 * Perintah ini menghitung ulang distribusi dari predictions yang sudah tersimpan,
 * jadi hasil lama tidak perlu dianalisis ulang ke NLP API.
 */
class RepairSentimentDistribution extends Command
{
    protected $signature = 'analysis:repair-distribution
                            {--apply : Simpan perubahan (tanpa flag ini hanya menampilkan laporan)}';

    protected $description = 'Hitung ulang sentiment_distribution dari predictions yang tersimpan';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $repaired = 0;
        $skipped = 0;

        $results = AnalysisResult::whereNotNull('predictions')
            ->whereNotNull('sentiment_distribution')
            ->get();

        if ($results->isEmpty()) {
            $this->info('Tidak ada hasil analisis yang perlu diperiksa.');
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($results as $result) {
            $recalculated = $this->recalculate($result->predictions ?? []);

            if ($recalculated === null) {
                $skipped++;
                continue;
            }

            $current = $result->sentiment_distribution;
            $currentTotal = round(array_sum($current), 2);

            // Toleransi pembulatan; distribusi yang benar selalu mendekati 100%
            if (abs($currentTotal - 100) <= 1) {
                $skipped++;
                continue;
            }

            $rows[] = [
                $result->text_analysis_id,
                $currentTotal . '%',
                $this->format($current),
                $this->format($recalculated),
            ];

            if ($apply) {
                $result->update(['sentiment_distribution' => $recalculated]);
            }

            $repaired++;
        }

        if ($repaired === 0) {
            $this->info("Semua distribusi sudah benar ({$skipped} hasil diperiksa).");
            return self::SUCCESS;
        }

        $this->table(['Analisis ID', 'Total lama', 'Distribusi lama', 'Distribusi baru'], $rows);

        if ($apply) {
            $this->info("{$repaired} hasil analisis diperbaiki.");
        } else {
            $this->warn("{$repaired} hasil analisis perlu diperbaiki. Jalankan ulang dengan --apply untuk menyimpan.");
        }

        return self::SUCCESS;
    }

    private function recalculate(array $predictions): ?array
    {
        $counts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        $total = 0;

        foreach ($predictions as $prediction) {
            $sentiment = $prediction['sentiment'] ?? null;

            if (is_array($sentiment)) {
                $sentiment = $sentiment['label'] ?? null;
            }

            $sentiment = strtolower((string) $sentiment);

            if (!array_key_exists($sentiment, $counts)) {
                continue;
            }

            $counts[$sentiment]++;
            $total++;
        }

        if ($total === 0) {
            return null;
        }

        return [
            'positive' => round(($counts['positive'] / $total) * 100, 2),
            'neutral' => round(($counts['neutral'] / $total) * 100, 2),
            'negative' => round(($counts['negative'] / $total) * 100, 2),
        ];
    }

    private function format(array $distribution): string
    {
        return sprintf(
            'P %s / N %s / Neg %s',
            $distribution['positive'] ?? 0,
            $distribution['neutral'] ?? 0,
            $distribution['negative'] ?? 0
        );
    }
}
