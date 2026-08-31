<?php

namespace App\Jobs;

use App\Models\AnalysisLog;
use App\Models\ModelTraining;
use App\Services\NLPApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

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

            $this->training->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);

            AnalysisLog::createLog(
                'retrained',
                $this->training->triggered_by,
                null,
                "Retraining model {$type} selesai",
                [
                    'training_id' => $this->training->id,
                    'samples' => $this->training->total_samples,
                    'result' => $result,
                ]
            );

            Log::info("Retraining {$type} selesai", ['result' => $result]);

        } catch (Exception $e) {
            $this->markFailed($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());
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
