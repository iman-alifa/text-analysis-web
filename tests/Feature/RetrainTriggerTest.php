<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Models\EvaluationSnapshot;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Loop active learning: koreksi user -> endpoint /api/retrain/* di NLP API.
 */
class RetrainTriggerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function seedCorrectedItems(int $count, string $sentiment = 'positive', array $aspects = []): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => $this->admin()->id,
            'title' => 'Batch koreksi',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => [],
            'total_records' => $count,
            'status' => 'completed',
        ]);

        for ($i = 0; $i < $count; $i++) {
            TrainingItem::create([
                'text_analysis_id' => $analysis->id,
                'text_content' => "kalimat koreksi {$i}",
                'predicted_sentiment' => 'neutral',
                'corrected_sentiment' => $sentiment,
                'corrected_aspects' => $aspects,
                'is_corrected' => true,
            ]);
        }

        return $analysis;
    }

    public function test_data_terkoreksi_dikirim_sebagai_job_retraining(): void
    {
        Queue::fake();
        $this->seedCorrectedItems(12);

        $response = $this->actingAs($this->admin())
            ->post(route('admin.training.trigger'), ['model_type' => 'sentiment', 'epochs' => 4]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $training = ModelTraining::firstOrFail();
        $this->assertSame('sentiment', $training->model_type);
        $this->assertSame(12, $training->total_samples);
        $this->assertSame(4, $training->epochs);

        Queue::assertPushed(RetrainModel::class);
    }

    public function test_memicu_training_merekam_snapshot_evaluasi(): void
    {
        Queue::fake();
        $analysis = $this->seedCorrectedItems(12);

        // Buat sebagian prediksi salah supaya akurasi tidak bulat 100%
        TrainingItem::where('text_analysis_id', $analysis->id)
            ->limit(3)
            ->update(['predicted_sentiment' => 'positive']);

        $this->actingAs($this->admin())
            ->post(route('admin.training.trigger'), ['model_type' => 'sentiment'])
            ->assertSessionHas('success');

        $snapshot = EvaluationSnapshot::firstOrFail();

        $this->assertSame(12, $snapshot->corrected_total);
        $this->assertSame(25.0, $snapshot->sentiment_accuracy); // 3 dari 12 benar
        $this->assertSame(12, $snapshot->sentiment_rows);
        $this->assertSame(ModelTraining::first()->id, $snapshot->model_training_id);
        $this->assertStringContainsString('Sebelum retraining', $snapshot->note);
    }

    public function test_ditolak_kalau_sampel_kurang_dari_minimum(): void
    {
        Queue::fake();
        $this->seedCorrectedItems(3);

        $this->actingAs($this->admin())
            ->post(route('admin.training.trigger'), ['model_type' => 'sentiment'])
            ->assertSessionHas('error');

        $this->assertSame(0, ModelTraining::count());
        Queue::assertNothingPushed();
    }

    public function test_training_yang_sedang_berjalan_tidak_bisa_ditumpuk(): void
    {
        Queue::fake();
        $this->seedCorrectedItems(12);

        ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'running',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.training.trigger'), ['model_type' => 'sentiment'])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_user_biasa_tidak_boleh_memicu_retraining(): void
    {
        Queue::fake();
        $this->seedCorrectedItems(12);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->post(route('admin.training.trigger'), ['model_type' => 'sentiment'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_job_memanggil_endpoint_retrain_dan_menyimpan_hasilnya(): void
    {
        config(['services.nlp_api.url' => 'http://nlp.test']);

        Http::fake([
            '*/api/retrain/sentiment' => Http::response([
                'status' => 'success',
                'results' => ['accuracy' => 0.91, 'samples' => 12],
            ], 200),
        ]);

        $training = ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'pending',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        $data = [];
        for ($i = 0; $i < 12; $i++) {
            $data[] = ['text' => "kalimat {$i}", 'label' => 'positive'];
        }

        (new RetrainModel($training, $data))->handle(app(\App\Services\NLPApiService::class));

        $training->refresh();
        $this->assertSame('completed', $training->status);
        $this->assertSame(0.91, $training->result['accuracy']);
        $this->assertNotNull($training->completed_at);

        Http::assertSent(fn ($request) => $request['epochs'] === 3 && count($request['training_data']) === 12);
    }

    public function test_job_menandai_gagal_saat_nlp_api_error(): void
    {
        config(['services.nlp_api.url' => 'http://nlp.test']);
        Http::fake(['*/api/retrain/sentiment' => Http::response(['detail' => 'model tidak tersedia'], 503)]);

        $training = ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'pending',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        $data = array_fill(0, 12, ['text' => 'kalimat', 'label' => 'positive']);

        try {
            (new RetrainModel($training, $data))->handle(app(\App\Services\NLPApiService::class));
        } catch (\Exception $e) {
            // job sengaja melempar ulang supaya queue mencatatnya sebagai failed
        }

        $training->refresh();
        $this->assertSame('failed', $training->status);
        $this->assertStringContainsString('model tidak tersedia', $training->error_message);
    }
}
