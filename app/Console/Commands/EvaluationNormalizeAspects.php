<?php

namespace App\Console\Commands;

use App\Models\EvaluationDataset;
use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Console\Command;

/**
 * Isi ulang bentuk dasar label aspek emas.
 *
 * Normalnya dikerjakan sekali saat pembekuan. Perintah ini untuk dua keadaan:
 * dataset yang dibekukan saat layanan NLP sedang mati, dan dataset lama yang
 * dibuat sebelum kolom bentuk dasar ada.
 */
class EvaluationNormalizeAspects extends Command
{
    protected $signature = 'evaluation:normalize-aspects {--dataset= : ID dataset; bawaannya semua yang belum lengkap}';

    protected $description = 'Isi bentuk dasar label aspek emas lewat stemmer di NLP API';

    public function handle(GoldenDatasetService $golden): int
    {
        $datasets = $this->option('dataset')
            ? EvaluationDataset::where('id', $this->option('dataset'))->get()
            : EvaluationDataset::whereHas('items', fn ($q) => $q->whereNotNull('gold_aspects')
                ->whereNull('gold_aspects_normalized'))->get();

        if ($datasets->isEmpty()) {
            $this->info('Tidak ada dataset yang perlu dinormalkan.');

            return self::SUCCESS;
        }

        $gagal = 0;

        foreach ($datasets as $dataset) {
            $perlu = $dataset->items()->whereNotNull('gold_aspects')->count();

            if ($perlu === 0) {
                $this->line("#{$dataset->id} {$dataset->name}: tidak punya label aspek, dilewati.");

                continue;
            }

            if ($golden->normalizeGoldAspects($dataset)) {
                $sisa = $dataset->items()
                    ->whereNotNull('gold_aspects')
                    ->whereNull('gold_aspects_normalized')
                    ->count();

                $this->info("#{$dataset->id} {$dataset->name}: {$perlu} baris dinormalkan"
                    .($sisa > 0 ? ", {$sisa} masih kosong" : '').'.');
            } else {
                $gagal++;
                $this->error("#{$dataset->id} {$dataset->name}: gagal. Pastikan nlp-api-service berjalan dan sudah punya endpoint /api/normalize/aspects.");
            }
        }

        if ($gagal > 0) {
            $this->newLine();
            $this->warn('Selama bentuk dasar belum terisi, metrik aspek tidak akan dihitung - itu disengaja. '
                .'Bentuk permukaan dibandingkan dengan bentuk dasar menghasilkan angka yang salah telak.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
