<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Models\AnalysisResult;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\AutoRetrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Loop active learning harus menutup dirinya sendiri.
 *
 * Sebelumnya pelatihan ulang hanya bisa dipicu dari /admin/training, sehingga
 * seluruh siklus berhenti bila tidak ada admin yang menekan tombol. Tes ini
 * memastikan pengguna biasa - tanpa admin di mana pun - cukup mengoreksi, dan
 * pelatihan ulang berjalan sendiri.
 */
class AutoRetrainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('analysis.auto_retrain', [
            'enabled' => true,
            'min_new_corrections' => 10,
            'cooldown_hours' => 6,
            'model_type' => 'sentiment',
            'epochs' => 3,
        ]);

        // Sengaja bukan admin: seluruh alur di bawah harus jalan tanpa admin.
        $this->user = User::factory()->create(['role' => 'user']);
    }

    /**
     * 60 baris: `min_new_corrections` yang diuji di sini boleh kecil, tetapi
     * dispatch-nya tetap tunduk pada ambang kelayakan RetrainingService (50).
     */
    private function analysis(int $jumlah = 60): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => $this->user->id,
            'title' => 'Opini warga',
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

    private function koreksi(TextAnalysis $analysis, int $jumlah): void
    {
        $ids = $analysis->trainingItems()->limit($jumlah)->pluck('id');

        TrainingItem::whereIn('id', $ids)->update([
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_by' => $this->user->id,
            'verified_at' => now(),
        ]);
    }

    private function siapkanBarisLatih(TextAnalysis $analysis): void
    {
        // Halaman koreksi memicu ekstraksi baris latih.
        $this->actingAs($this->user)->get(route('analysis.feedback', $analysis->id))->assertOk();
    }

    // --------------------------------------------------------------- syarat

    public function test_tidak_memicu_sebelum_ambang_tercapai(): void
    {
        Queue::fake();
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 5);

        $hasil = app(AutoRetrainService::class)->run();

        $this->assertFalse($hasil['triggered']);
        $this->assertStringContainsString('5 dari minimal 10', $hasil['reason']);
        Queue::assertNothingPushed();
    }

    public function test_memicu_setelah_ambang_tercapai(): void
    {
        Queue::fake();
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 60);

        $hasil = app(AutoRetrainService::class)->run();

        $this->assertTrue($hasil['triggered']);
        Queue::assertPushed(RetrainModel::class);

        // triggered_by null menandai pemicu sistem, bukan orang.
        $this->assertDatabaseHas('model_trainings', [
            'model_type' => 'sentiment',
            'triggered_by' => null,
        ]);
    }

    public function test_menghitung_koreksi_baru_bukan_total(): void
    {
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 12);

        // Pelatihan pertama sudah memakai 12 koreksi itu.
        ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'completed',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        // Kalau yang dihitung jumlah total, setiap koreksi berikutnya akan
        // memicu pelatihan lagi selamanya.
        $this->assertSame(0, app(AutoRetrainService::class)->newCorrectionCount());
    }

    public function test_jeda_menahan_pelatihan_berikutnya(): void
    {
        Queue::fake();
        $analysis = $this->analysis(30);
        $this->siapkanBarisLatih($analysis);

        $latihan = ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'completed',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        // created_at tidak ada di $fillable, jadi harus disetel terpisah.
        $latihan->forceFill(['created_at' => now()->subHour()])->save();

        $this->koreksi($analysis, 15);

        $hasil = app(AutoRetrainService::class)->run();

        $this->assertFalse($hasil['triggered']);
        $this->assertStringContainsString('jeda', $hasil['reason']);
        Queue::assertNothingPushed();
    }

    public function test_tidak_menumpuk_saat_pelatihan_berjalan(): void
    {
        Queue::fake();
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 12);

        ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'running',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        $this->assertFalse(app(AutoRetrainService::class)->run()['triggered']);
        Queue::assertNothingPushed();
    }

    public function test_bisa_dimatikan_lewat_konfigurasi(): void
    {
        config()->set('analysis.auto_retrain.enabled', false);
        Queue::fake();

        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 12);

        $this->assertFalse(app(AutoRetrainService::class)->run()['triggered']);
        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------- lewat alur pengguna

    public function test_pengguna_biasa_memicu_pelatihan_hanya_dengan_mengoreksi(): void
    {
        Queue::fake();
        $analysis = $this->analysis(60);
        $this->siapkanBarisLatih($analysis);

        // 59 baris dikoreksi lebih dulu, satu terakhir lewat formulir.
        $this->koreksi($analysis, 59);
        $terakhir = $analysis->trainingItems()->where('is_corrected', false)->first();

        $this->actingAs($this->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections' => [['item_id' => $terakhir->id, 'corrected_sentiment' => 'positive']],
            ])
            ->assertRedirect()
            // Putaran yang benar-benar jalan DISEBUTKAN. Dulu jalur ini sengaja
            // bisu, tetapi pemilik proyek menjalankan active learning dari
            // antarmuka: menekan Kirim lalu tidak tahu apakah putarannya jalan
            // berarti satu-satunya cara memastikan adalah membuka basis data.
            // Yang tetap bisu adalah keadaan rutin - koreksi belum cukup, masih
            // dalam jeda - lihat test_koreksi_biasa_tidak_memicu_apa_apa.
            ->assertSessionHas('feedback_success', fn ($pesan) => str_contains($pesan, 'active learning'));

        // Tidak ada admin yang terlibat sama sekali.
        Queue::assertPushed(RetrainModel::class);
        $this->assertSame(0, User::where('role', 'admin')->count());
    }

    public function test_koreksi_biasa_tidak_memicu_apa_apa(): void
    {
        Queue::fake();
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $item = $analysis->trainingItems()->first();

        $this->actingAs($this->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections' => [['item_id' => $item->id, 'corrected_sentiment' => 'positive']],
            ])
            ->assertRedirect();

        Queue::assertNothingPushed();
    }

    public function test_halaman_koreksi_tidak_menyinggung_pelatihan_ulang(): void
    {
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 4);

        // Pelatihan ulang berjalan di belakang layar. Menampilkan hitungannya
        // kepada pengguna hanya menambah istilah teknis pada alur yang
        // seharusnya sederhana: baca, koreksi, simpan.
        $this->actingAs($this->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->assertDontSee('dilatih ulang otomatis')
            ->assertDontSee('Pelatihan ulang');
    }

    public function test_status_otomatis_tampil_di_halaman_pengawasan_admin(): void
    {
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 4);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.training.index'))
            ->assertOk()
            ->assertSee('Otomatis: 4/10 koreksi baru');
    }

    // ------------------------------------------------------------- perintah

    public function test_perintah_artisan_memicu_pelatihan(): void
    {
        Queue::fake();
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 60);

        $this->artisan('analysis:auto-retrain')
            ->expectsOutputToContain('Dikirim')
            ->assertSuccessful();

        Queue::assertPushed(RetrainModel::class);
    }

    public function test_dry_run_tidak_mengantrekan_apa_pun(): void
    {
        Queue::fake();
        $analysis = $this->analysis();
        $this->siapkanBarisLatih($analysis);
        $this->koreksi($analysis, 12);

        $this->artisan('analysis:auto-retrain --dry-run')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
