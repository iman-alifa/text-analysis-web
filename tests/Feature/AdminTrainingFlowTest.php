<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\CustomStopword;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Menjalankan setiap tombol pada halaman active learning / retraining, bukan
 * hanya merender halamannya. Rute GET sudah tersapu; yang mengubah data hanya
 * bisa dibuktikan dengan benar-benar memanggilnya.
 */
class AdminTrainingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TextAnalysis $analysis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->analysis = $this->buatAnalisis();
    }

    /**
     * 60 baris, bukan 12: ambang kelayakan RetrainingService::MIN_LAYAK (50)
     * kini berlaku untuk tombol admin juga, bukan hanya untuk perintah.
     */
    private function buatAnalisis(int $jumlah = 60): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => $this->admin->id,
            'title' => 'Korpus latih',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => range(1, $jumlah),
            'total_records' => $jumlah,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => array_map(fn ($i) => [
                'original_index' => $i,
                'text' => "kalimat nomor {$i}",
                'sentiment' => 'neutral',
                'confidence' => 0.5,
            ], range(0, $jumlah - 1)),
            'sentiment_distribution' => ['positive' => 0.0, 'neutral' => 100.0, 'negative' => 0.0],
        ]);

        return $analysis->fresh('result');
    }

    private function koreksiSemua(): void
    {
        $this->analysis->trainingItems()->update([
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_by' => $this->admin->id,
            'verified_at' => now(),
        ]);
    }

    // ------------------------------------------------------------- sinkron

    public function test_tombol_sinkronkan_membuat_baris_latih(): void
    {
        $this->assertSame(0, $this->analysis->trainingItems()->count());

        $this->actingAs($this->admin)
            ->post(route('admin.training.sync'))
            ->assertRedirect();

        $this->assertSame(60, $this->analysis->trainingItems()->count());
    }

    public function test_sinkronkan_dua_kali_tidak_menggandakan_baris(): void
    {
        // Pemanggilan kedua dulu menggandakan seluruh baris beserta koreksinya.
        $this->actingAs($this->admin)->post(route('admin.training.sync'));
        $this->actingAs($this->admin)->post(route('admin.training.sync'));

        $this->assertSame(60, $this->analysis->trainingItems()->count());
    }

    // ------------------------------------------------------------- koreksi

    public function test_koreksi_satu_baris_dari_ruang_kerja_admin(): void
    {
        $this->actingAs($this->admin)->post(route('admin.training.sync'));
        $item = $this->analysis->trainingItems()->first();

        $this->actingAs($this->admin)
            ->post(route('admin.training.update', $item->id), [
                'corrected_sentiment' => 'negative',
                'corrected_aspects' => 'harga, mutu',
            ])
            ->assertSuccessful();

        $item->refresh();
        $this->assertSame('negative', $item->corrected_sentiment);
        $this->assertTrue((bool) $item->is_corrected);
    }

    // ------------------------------------------------------------ stopword

    public function test_tambah_dan_hapus_stopword(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.training.stopword'), ['word' => 'yaaa'])
            ->assertRedirect();

        $stopword = CustomStopword::where('word', 'yaaa')->first();
        $this->assertNotNull($stopword);

        $this->actingAs($this->admin)
            ->delete(route('admin.training.stopword.delete', $stopword->id))
            ->assertRedirect();

        $this->assertNull(CustomStopword::find($stopword->id));
    }

    // ------------------------------------------------------------- pratinjau

    public function test_periksa_data_latih_memanggil_endpoint_pratinjau(): void
    {
        $this->actingAs($this->admin)->post(route('admin.training.sync'));
        $this->koreksiSemua();

        Http::fake(['*/api/retrain/preview' => Http::response([
            'status' => 'success',
            'label_distribution' => ['positive' => 12],
            'imbalance_ratio' => 1.0,
            'majority_baseline_accuracy' => 1.0,
        ])]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.training.preview').'?type=sentiment')
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/retrain/preview'));
    }

    // -------------------------------------------------------------- retrain

    public function test_tombol_latih_ulang_mengantre_pekerjaan(): void
    {
        $this->actingAs($this->admin)->post(route('admin.training.sync'));
        $this->koreksiSemua();

        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.training.trigger'), [
                'model_type' => 'sentiment',
                'text_analysis_id' => (string) $this->analysis->id,
            ])
            ->assertRedirect();

        Queue::assertPushed(\App\Jobs\RetrainModel::class);
        $this->assertDatabaseHas('model_trainings', ['model_type' => 'sentiment']);
    }

    public function test_latih_ulang_ditolak_bila_koreksi_kurang_dari_sepuluh(): void
    {
        $this->actingAs($this->admin)->post(route('admin.training.sync'));

        // API mensyaratkan minimal 10 sampel; menembakkannya tanpa itu hanya
        // menghasilkan 422 di worker, jauh dari mata pengguna.
        $this->analysis->trainingItems()->limit(3)->update([
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_by' => $this->admin->id,
            'verified_at' => now(),
        ]);

        Queue::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.training.trigger'), [
                'model_type' => 'sentiment',
                'text_analysis_id' => (string) $this->analysis->id,
            ])
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    // --------------------------------------------------------------- ekspor

    public function test_ekspor_csv_berisi_baris_terkoreksi(): void
    {
        $this->actingAs($this->admin)->post(route('admin.training.sync'));
        $this->koreksiSemua();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.training.export'))
            ->assertOk();

        $isi = $response->streamedContent();

        $this->assertStringContainsString('kalimat nomor 0', $isi);
        $this->assertStringContainsString('positive', $isi);
    }

    // ------------------------------------------------------------ hak akses

    public function test_seluruh_aksi_tertutup_untuk_pengguna_biasa(): void
    {
        $biasa = User::factory()->create(['role' => 'user']);

        $this->actingAs($biasa)->post(route('admin.training.sync'))->assertForbidden();
        $this->actingAs($biasa)->post(route('admin.training.trigger'))->assertForbidden();
        $this->actingAs($biasa)->post(route('admin.training.stopword'), ['word' => 'x'])->assertForbidden();
        $this->actingAs($biasa)->get(route('admin.training.export'))->assertForbidden();
    }
}
