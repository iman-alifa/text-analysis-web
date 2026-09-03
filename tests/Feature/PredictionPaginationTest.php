<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\PredictionQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daftar prediksi dipaginasi, disaring, dan dicari di server.
 *
 * Sebelumnya seluruh baris dirender sekaligus lalu disaring di browser,
 * sehingga halaman hasil untuk 289 prediksi berukuran 2,7 MB.
 */
class PredictionPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(int $jumlah = 60): TextAnalysis
    {
        $predictions = [];
        $labels = ['positive', 'neutral', 'negative'];

        for ($i = 0; $i < $jumlah; $i++) {
            $predictions[] = [
                'text' => "kalimat nomor {$i}".($i === 7 ? ' mengandung kata unik jaringan' : ''),
                'sentiment' => $labels[$i % 3],
                'confidence' => $i < 5 ? 0.40 + ($i / 100) : 0.99,
                'method' => 'indobert',
                'original_index' => $i,
            ];
        }

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis besar',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => array_column($predictions, 'text'),
            'total_records' => $jumlah,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => $predictions,
            'sentiment_distribution' => ['positive' => 34.0, 'neutral' => 33.0, 'negative' => 33.0],
            'metrics' => [
                'total_analyzed' => $jumlah,
                // Lima baris paling tidak yakin, urut dari yang terendah
                'review_queue' => ['threshold' => 0.94, 'count' => 5, 'share' => 0.083, 'indices' => [0, 1, 2, 3, 4]],
            ],
        ]);

        return $analysis;
    }

    public function test_halaman_pertama_dirender_di_server(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Tanpa JavaScript pun daftarnya tetap tampil
        $this->assertSame(
            PredictionQueryService::PER_PAGE,
            substr_count($html, 'class="prediction-card')
        );
        $this->assertStringContainsString('Halaman 1 dari 3', $html);
    }

    public function test_halaman_berikutnya_diambil_lewat_endpoint(): void
    {
        $analysis = $this->analysis();

        $response = $this->actingAs($analysis->user)
            ->getJson(route('analysis.predictions', $analysis->id).'?page=3')
            ->assertOk()
            ->assertJsonPath('meta.page', 3)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.from', 51)
            ->assertJsonPath('meta.to', 60);

        // Nomor urut tetap melanjutkan halaman sebelumnya
        $this->assertStringContainsString('60', $response->json('html'));
    }

    public function test_penyaringan_sentimen_dikerjakan_di_server(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->getJson(route('analysis.predictions', $analysis->id).'?filter=positive')
            ->assertOk()
            ->assertJsonPath('meta.filtered', 20)
            ->assertJsonPath('meta.total', 60);
    }

    public function test_antrean_tinjauan_diurutkan_dari_yang_paling_ragu(): void
    {
        $analysis = $this->analysis();

        $response = $this->actingAs($analysis->user)
            ->getJson(route('analysis.predictions', $analysis->id).'?filter=review')
            ->assertOk()
            ->assertJsonPath('meta.filtered', 5);

        $html = $response->json('html');

        // Baris paling tidak yakin (indeks 0) muncul lebih dulu
        $this->assertLessThan(
            strpos($html, 'kalimat nomor 4'),
            strpos($html, 'kalimat nomor 0')
        );
    }

    public function test_pencarian_dikerjakan_di_server(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->getJson(route('analysis.predictions', $analysis->id).'?q=jaringan')
            ->assertOk()
            ->assertJsonPath('meta.filtered', 1);
    }

    public function test_halaman_di_luar_jangkauan_dijepit(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->getJson(route('analysis.predictions', $analysis->id).'?page=99')
            ->assertOk()
            ->assertJsonPath('meta.page', 3);
    }

    public function test_penyaring_tidak_dikenal_ditolak(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->getJson(route('analysis.predictions', $analysis->id).'?filter=rahasia')
            ->assertStatus(422);
    }

    public function test_analisis_milik_pengguna_lain_tidak_bisa_dibaca(): void
    {
        $analysis = $this->analysis();

        $this->actingAs(User::factory()->create())
            ->getJson(route('analysis.predictions', $analysis->id))
            ->assertNotFound();
    }

    public function test_ikon_sentimen_tidak_lagi_diulang_tiap_kartu(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Jalur SVG hanya muncul sekali sebagai sprite, bukan sekali per kartu
        $this->assertSame(1, substr_count($html, 'id="ikon-sentimen-positive"'));
        $this->assertStringContainsString('#ikon-sentimen-', $html);
    }
}
