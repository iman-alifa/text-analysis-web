<?php

namespace App\Jobs;

use App\Exceptions\RetrainBusyException;
use App\Models\AnalysisLog;
use App\Models\ModelTraining;
use App\Services\AutoEvaluationService;
use App\Services\NLPApiService;
use App\Support\PoolFingerprint;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kirim data koreksi (active learning) ke endpoint retraining NLP API.
 *
 * Dijalankan lewat queue karena fine-tuning BERT jauh melewati batas waktu
 * request HTTP biasa; admin cukup memantau statusnya di halaman training.
 */
class RetrainModel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Fine-tuning di CPU VPS bisa mendekati satu jam per modul.
     *
     * Rantai: HTTP retrain (services.nlp_api.retrain_timeout, 6600) < nilai ini
     * < retry_after koneksi antrean 'training' (7500).
     */
    public $timeout = 7200;

    public $tries = 1;      // jangan mengulang training yang mahal secara otomatis

    protected ModelTraining $training;

    protected array $trainingData;

    public function __construct(ModelTraining $training, array $trainingData)
    {
        $this->training = $training;
        $this->trainingData = $trainingData;

        // Antrean sendiri dengan worker sendiri, supaya pelatihan yang memakan
        // puluhan menit tidak menahan analisis pengguna di belakangnya, dan
        // retry_after-nya cukup panjang untuk pelatihan yang masih berjalan.
        $this->onConnection(config('queue.training_connection', 'training'));
        $this->onQueue('training');
    }

    public function handle(NLPApiService $nlpService, ?AutoEvaluationService $evaluasiOtomatis = null): void
    {
        try {
            $this->latih($nlpService);
        } finally {
            // Dipanggil apa pun hasilnya, termasuk bila pelatihan melempar:
            // job ini bisa saja yang terakhir dalam putaran, dan modul lain
            // di putaran yang sama mungkin berhasil dan perlu diukur.
            // Bobot aktif mungkin baru saja berganti; halaman publik jangan
            // menunggu cache lima menit untuk mengetahuinya.
            app(\App\Services\ActiveModelService::class)->lupakan();

            try {
                ($evaluasiOtomatis ?? app(AutoEvaluationService::class))->setelahPelatihan($this->training->fresh() ?? $this->training);
            } catch (Throwable $e) {
                Log::error('Evaluasi otomatis gagal diantrekan: '.$e->getMessage());
            }
        }
    }

    private function latih(NLPApiService $nlpService): void
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

            // Sidik jari isi kolam. Dihitung di sini dari muatan yang BENAR-BENAR
            // dikirim, bukan dibaca dari respons: layanan menuliskannya ke
            // `provenance.json` di sebelah checkpoint, tetapi belum
            // mengembalikannya di badan respons `/api/retrain/*`. Kalau suatu
            // saat dikembalikan, nilainya yang dipakai - dan selisih antara
            // keduanya justru temuan yang layak dilihat.
            //
            // Gunanya membaca dua putaran berturut-turut: sidik jari yang SAMA
            // berarti kolamnya tidak bertambah (keliru menurut protokol kolam),
            // dan sidik jari yang berbeda pada putaran yang seharusnya
            // mengulang hal yang sama berarti kolamnya berubah diam-diam.
            $result['sidik_jari_sumber'] = isset($result['sidik_jari_kolam']) ? 'layanan' : 'laravel';
            $result['sidik_jari_kolam'] ??= PoolFingerprint::ofPayload($this->trainingData);

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

        } catch (RetrainBusyException $e) {
            // Bukan kegagalan: layanan sedang melatih model yang sama, jadi
            // pelatihan ini dilewati. Koreksinya tetap tersimpan dan akan
            // ikut pada putaran berikutnya.
            Log::info("Retraining {$type} dilewati: ".$e->getMessage());

            $this->training->update([
                'status' => 'skipped',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
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

    /*
     * Evaluasi hold-out dipicu di handle() lewat AutoEvaluationService, di
     * akhir putaran (bukan di akhir setiap job). Sempat manual atas permintaan
     * pemilik proyek, lalu dijadikan otomatis lagi (24 Sep 2026) supaya loop
     * active learning berjalan tanpa campur tangan.
     */

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
