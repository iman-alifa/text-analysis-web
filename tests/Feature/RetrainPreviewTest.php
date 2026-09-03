<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Laporan kesiapan data latih dipanggil sebelum retraining, supaya data yang
 * timpang terlihat sebelum model dilatih pada data itu.
 */
class RetrainPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp_api.url' => 'http://nlp.test']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function seedCorrections(int $jumlah = 12): void
    {
        $analysis = TextAnalysis::create([
            'user_id' => $this->admin()->id,
            'title' => 'Sumber koreksi',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => 'completed',
        ]);

        for ($i = 0; $i < $jumlah; $i++) {
            TrainingItem::create([
                'text_analysis_id' => $analysis->id,
                'text_content' => "kalimat {$i}",
                'predicted_sentiment' => 'neutral',
                'corrected_sentiment' => 'negative',
                'corrected_aspects' => ['pelayanan'],
                'is_corrected' => true,
                'confidence_score' => 0.5,
            ]);
        }
    }

    private function fakePreview(): void
    {
        Http::fake([
            '*/api/retrain/preview' => Http::response([
                'status' => 'success',
                'model_type' => 'sentiment',
                'samples_valid' => 12,
                'samples_invalid' => 0,
                'label_distribution' => [
                    'counts' => ['negative' => 12, 'neutral' => 0, 'positive' => 0],
                    'total' => 12,
                    'imbalance_ratio' => 12,
                    'majority_class' => 'negative',
                ],
                'split' => ['seed' => 42, 'train_size' => 10, 'val_size' => 2, 'val_composition' => ['negative' => 2]],
                'class_weights' => ['positive' => 1.0, 'neutral' => 1.0, 'negative' => 1.0],
                'majority_baseline_accuracy' => 1.0,
                'warnings' => ['Data sangat timpang (rasio 12:1).'],
            ], 200),
        ]);
    }

    public function test_melaporkan_komposisi_data_tanpa_melatih(): void
    {
        $this->seedCorrections();
        $this->fakePreview();

        $this->actingAs($this->admin())
             ->getJson(route('admin.training.preview'))
             ->assertOk()
             ->assertJsonPath('reports.sentiment.available', true)
             ->assertJsonPath('reports.sentiment.label_distribution.imbalance_ratio', 12)
             // 1.0 kembali sebagai 1 setelah JSON encode
             ->assertJsonPath('reports.sentiment.majority_baseline_accuracy', 1)
             ->assertJsonPath('minimum_samples', 10);

        // Tidak boleh menyentuh endpoint pelatihan
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/retrain/sentiment'));
    }

    public function test_meneruskan_peringatan_dari_api(): void
    {
        $this->seedCorrections();
        $this->fakePreview();

        $this->actingAs($this->admin())
             ->getJson(route('admin.training.preview'))
             ->assertOk()
             ->assertJsonPath('reports.sentiment.warnings.0', 'Data sangat timpang (rasio 12:1).');
    }

    public function test_tetap_melaporkan_saat_nlp_api_mati(): void
    {
        $this->seedCorrections();
        Http::fake(['*/api/retrain/preview' => Http::response('down', 500)]);

        $this->actingAs($this->admin())
             ->getJson(route('admin.training.preview'))
             ->assertOk()
             ->assertJsonPath('success', true)
             ->assertJsonPath('reports.sentiment.available', false);
    }

    public function test_menolak_saat_belum_ada_koreksi(): void
    {
        $this->actingAs($this->admin())
             ->getJson(route('admin.training.preview'))
             ->assertStatus(422)
             ->assertJsonPath('success', false);
    }

    public function test_hanya_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
             ->getJson(route('admin.training.preview'))
             ->assertForbidden();
    }

    /**
     * Rute literal sempat tertelan oleh /training/{id} yang terdaftar lebih dulu,
     * sehingga tombol Export CSV selalu berakhir 404.
     */
    public function test_rute_literal_tidak_tertelan_oleh_parameter_id(): void
    {
        $this->seedCorrections();
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.training.export'))->assertOk();
        $this->actingAs($admin)->getJson(route('admin.training.preview'))->assertOk();
    }
}
