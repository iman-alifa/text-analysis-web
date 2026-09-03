<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Models\ModelTraining;
use App\Models\User;
use App\Services\NLPApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NLP API menolak menyimpan checkpoint yang menurunkan metrik validasi dan
 * mengembalikan bobot lama. Itu bukan kegagalan, tetapi juga bukan keberhasilan
 * dan tidak boleh tercatat sebagai "completed".
 */
class RetrainRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp_api.url' => 'http://nlp.test']);
    }

    private function training(): ModelTraining
    {
        return ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'pending',
            'total_samples' => 20,
            'epochs' => 3,
            'learning_rate' => 0.00002,
            'triggered_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);
    }

    /** Minimal 10 sampel, sesuai batas endpoint retraining. */
    private function sampleData(): array
    {
        return collect(range(1, 12))
            ->map(fn ($i) => ['text' => "kalimat {$i}", 'label' => 'positive'])
            ->all();
    }

    private function fakeRetrain(bool $saved, float $delta): void
    {
        Http::fake([
            '*/api/retrain/sentiment' => Http::response([
                'status' => 'success',
                'results' => [
                    'saved' => $saved,
                    'rejected_for_regression' => !$saved,
                    'weighted_f1_delta' => $delta,
                    'metrics_before' => ['weighted_f1' => 0.8123, 'accuracy' => 0.82],
                    'metrics_after' => ['weighted_f1' => round(0.8123 + $delta, 4), 'accuracy' => 0.79],
                    'samples_used' => 20,
                    'samples_skipped' => 2,
                    'seed' => 42,
                ],
            ], 200),
        ]);
    }

    public function test_checkpoint_yang_ditolak_tidak_dicatat_sebagai_berhasil(): void
    {
        $this->fakeRetrain(saved: false, delta: -0.0412);
        $training = $this->training();

        (new RetrainModel($training, $this->sampleData()))
            ->handle(new NLPApiService());

        $training->refresh();

        $this->assertSame('rejected', $training->status);
        $this->assertStringContainsString('Weighted F1 0.8123 -> 0.7711', $training->error_message);
        $this->assertStringContainsString('-0.0412', $training->error_message);
        // Hasil mentah tetap disimpan untuk penelusuran
        $this->assertFalse($training->result['saved']);
    }

    public function test_checkpoint_yang_membaik_dicatat_selesai(): void
    {
        $this->fakeRetrain(saved: true, delta: 0.0355);
        $training = $this->training();

        (new RetrainModel($training, $this->sampleData()))
            ->handle(new NLPApiService());

        $training->refresh();

        $this->assertSame('completed', $training->status);
        $this->assertNull($training->error_message);
    }

    public function test_respons_lama_tanpa_penanda_tetap_dianggap_berhasil(): void
    {
        // Kompatibilitas: build service lama tidak mengirim saved/rejected
        Http::fake([
            '*/api/retrain/sentiment' => Http::response([
                'status' => 'success',
                'results' => ['epochs_completed' => 3, 'val_accuracy' => 0.9],
            ], 200),
        ]);

        $training = $this->training();

        (new RetrainModel($training, $this->sampleData()))
            ->handle(new NLPApiService());

        $this->assertSame('completed', $training->refresh()->status);
    }

    public function test_status_ditolak_tidak_menghalangi_training_berikutnya(): void
    {
        $training = $this->training();
        $training->update(['status' => 'rejected']);

        // scopeRunning hanya mencakup pending/running
        $this->assertFalse(ModelTraining::running()->exists());
    }
}
