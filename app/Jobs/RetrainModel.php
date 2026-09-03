<?php

namespace App\Jobs;

use App\Models\AnalysisLog;
use App\Models\ModelTraining;
use App\Services\NLPApiService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Kirim data koreksi (active learning) ke endpoint retraining NLP API.
 *
 * Dijalankan lewat queue karena fine-tuning BERT jauh melewati batas waktu
 * request HTTP biasa; admin cukup memantau statusnya di halaman training.
 */
class RetrainModel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // fine-tuning bisa berjam-jam untuk dataset besar

    public $tries = 1;      // jangan mengulang training yang mahal secara otomatis

    protected ModelTraining $training;

    protected array $trainingData;

    public function __construct(ModelTraining $training, array $trainingData)
    {
        $this->training = $training;
        $this->trainingData = $trainingData;
    }

    public function handle(NLPApiService $nlpService): void
    {
        $type = $this->training->model_type;

        try {
            $this->training->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

            Log::info("Memulai retraining {$type}", [
                'training_id' => $this->training->id,
                'samples' => count($this->trainingData),
            ]);

            $result = $type === 'sentiment'
                ? $nlpService->retrainSentiment(
                    $this->trainingData,
                    $this->training->epochs,
                    (float) $this->training->learning_rate
                )
                : $nlpService->retrainAspect(
                    $this->trainingData,
                    $this->training->epochs,
                    (float) $this->training->learning_rate
                );

            // NLP API menolak checkpoint yang menurunkan metrik validasi dan
            // mengembalikan bobot lama. Itu hasil yang sah, bukan kegagalan,
            // tapi juga bukan keberhasilan - mencatatnya "completed" membuat
            // admin mengira model membaik padahal tidak.
            $ditolak = ($result['rejected_for_regression'] ?? false) === true
                    || ($result['saved'] ?? true) === false;

            $this->training->update([
                'status' => $ditolak ? 'rejected' : 'completed',
                'result' => $result,
                'error_message' => $ditolak ? $this->regressionMessage($result) : null,
                'completed_at' => now(),
            ]);

            AnalysisLog::createLog(
                'retrained',
                $this->training->triggered_by,
                null,
                $ditolak
                    ? "Retraining model {$type} ditolak: metrik validasi menurun"
                    : "Retraining model {$type} selesai",
                [
                    'training_id' => $this->training->id,
                    'samples' => $this->training->total_samples,
                    'saved' => ! $ditolak,
                    'result' => $result,
                ]
            );

            Log::info("Retraining {$type} selesai", [
                'saved' => ! $ditolak,
                'result' => $result,
            ]);

        } catch (Exception $e) {
            $this->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());
    }

    /**
     * Ringkas alasan penolakan supaya terbaca langsung di tabel riwayat.
     */
    private function regressionMessage(array $result): string
    {
        $delta = $result['weighted_f1_delta'] ?? null;
        $sebelum = $result['metrics_before']['weighted_f1'] ?? null;
        $sesudah = $result['metrics_after']['weighted_f1'] ?? null;

        $pesan = 'Checkpoint ditolak: metrik validasi menurun, bobot lama dipertahankan.';

        if ($sebelum !== null && $sesudah !== null) {
            $pesan .= sprintf(' Weighted F1 %s -> %s', $sebelum, $sesudah);

            if ($delta !== null) {
                $pesan .= sprintf(' (%+.4f)', $delta);
            }

            $pesan .= '.';
        }

        return $pesan;
    }

    private function markFailed(string $message): void
    {
        Log::error("Retraining {$this->training->model_type} gagal: {$message}");

        $this->training->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
