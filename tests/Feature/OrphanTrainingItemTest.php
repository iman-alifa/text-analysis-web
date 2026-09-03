<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TextAnalysis memakai soft delete, sehingga cascade delete pada
 * training_items tidak pernah berjalan dan baris milik analisis yang dihapus
 * tetap tertinggal. Baris itu tidak muncul di daftar file mana pun, jadi tidak
 * boleh ikut menghitung statistik maupun ikut dilatih.
 */
class OrphanTrainingItemTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function analysisWithItems(User $user, int $jumlah, string $label): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => "Analisis {$label}",
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => 'completed',
        ]);

        for ($i = 0; $i < $jumlah; $i++) {
            TrainingItem::create([
                'text_analysis_id' => $analysis->id,
                'text_content' => "{$label} kalimat {$i}",
                'predicted_sentiment' => 'neutral',
                'corrected_sentiment' => 'negative',
                'corrected_aspects' => ['pelayanan'],
                'is_corrected' => true,
                'confidence_score' => 0.5,
            ]);
        }

        return $analysis;
    }

    public function test_statistik_tidak_menghitung_baris_analisis_terhapus(): void
    {
        $admin = $this->admin();
        $this->analysisWithItems($admin, 12, 'aktif');
        $terhapus = $this->analysisWithItems($admin, 30, 'terhapus');

        $terhapus->delete(); // soft delete

        $this->assertSame(42, TrainingItem::count(), 'baris yatim memang masih ada di tabel');

        $html = $this->actingAs($admin)->get(route('admin.training.index'))->assertOk()->getContent();

        // Yang tampil hanya 12, bukan 42
        $this->assertStringContainsString('>12<', $html);
        $this->assertStringNotContainsString('>42<', $html);
    }

    public function test_data_latih_tidak_menyertakan_baris_analisis_terhapus(): void
    {
        Queue::fake();

        $admin = $this->admin();
        $this->analysisWithItems($admin, 12, 'aktif');
        $terhapus = $this->analysisWithItems($admin, 30, 'terhapus');
        $terhapus->delete();

        $this->actingAs($admin)
            ->post(route('admin.training.trigger'), ['model_type' => 'sentiment'])
            ->assertSessionHas('success');

        $training = \App\Models\ModelTraining::firstOrFail();

        // 12, bukan 42
        $this->assertSame(12, $training->total_samples);
    }

    public function test_pemeriksaan_data_juga_mengabaikan_baris_yatim(): void
    {
        Http::fake(['*/api/retrain/preview' => Http::response([
            'status' => 'success',
            'model_type' => 'sentiment',
            'samples_valid' => 12,
        ], 200)]);

        $admin = $this->admin();
        $this->analysisWithItems($admin, 12, 'aktif');
        $terhapus = $this->analysisWithItems($admin, 30, 'terhapus');
        $terhapus->delete();

        $this->actingAs($admin)->getJson(route('admin.training.preview'))->assertOk();

        Http::assertSent(fn ($request) => count($request['training_data']) === 12);
    }

    public function test_export_csv_tidak_memuat_baris_tanpa_analisis(): void
    {
        $admin = $this->admin();
        $this->analysisWithItems($admin, 3, 'aktif');
        $terhapus = $this->analysisWithItems($admin, 4, 'terhapus');
        $terhapus->delete();

        $isi = $this->actingAs($admin)->get(route('admin.training.export'))->streamedContent();

        $this->assertStringContainsString('aktif kalimat 0', $isi);
        $this->assertStringNotContainsString('terhapus kalimat 0', $isi);
        $this->assertStringNotContainsString('Unknown', $isi);
    }
}
