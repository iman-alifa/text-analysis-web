<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tampilan antrean tinjauan, penanda asal prediksi, dan catatan mutu data
 * pada halaman hasil analisis.
 */
class ReviewQueueDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(array $metrics, array $predictions): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis sentimen',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => array_map(fn ($p) => $p['text'], $predictions),
            'total_records' => count($predictions),
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => $predictions,
            'sentiment_distribution' => ['positive' => 50.0, 'neutral' => 25.0, 'negative' => 25.0],
            'metrics' => $metrics,
            'summary' => 'ringkasan',
        ]);

        return $analysis;
    }

    private function predictions(): array
    {
        return [
            ['text' => 'kalimat yakin', 'sentiment' => 'positive', 'confidence' => 0.99, 'method' => 'indobert', 'original_index' => 0],
            ['text' => 'kalimat ragu', 'sentiment' => 'neutral', 'confidence' => 0.42, 'method' => 'indobert', 'original_index' => 1],
            ['text' => 'kalimat tanpa model', 'sentiment' => 'negative', 'confidence' => 0.60, 'method' => 'rule-based', 'original_index' => 2],
            ['text' => 'kalimat kosong', 'sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'empty', 'original_index' => 3],
        ];
    }

    public function test_tab_tinjauan_dan_catatan_mutu_tidak_ditampilkan(): void
    {
        // Dihapus dari bagian prediksi atas permintaan pemilik proyek
        // (24 Sep 2026). Diuji dengan data yang DULU memunculkan ketiganya,
        // supaya yang terbukti adalah penghapusannya, bukan sekadar datanya
        // yang kosong.
        $analysis = $this->makeAnalysis([
            'total_analyzed' => 3,
            'total_empty' => 2,
            'total_truncated' => 1,
            'review_queue' => ['threshold' => 0.94, 'count' => 2, 'share' => 0.6667, 'indices' => [1, 2]],
        ], $this->predictions());

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertDontSee('Perlu Ditinjau (2)')
            ->assertDontSee('jangan memakainya untuk mengukur akurasi', false)
            ->assertDontSee('Catatan mutu data')
            // Filter sentimen biasa tetap ada.
            ->assertSee('data-filter="positive"', false);
    }

    public function test_baris_antrean_ditandai_dengan_peringkatnya(): void
    {
        $analysis = $this->makeAnalysis([
            'review_queue' => ['threshold' => 0.94, 'count' => 2, 'share' => 0.6667, 'indices' => [2, 1]],
        ], $this->predictions());

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // indices [2, 1] berarti teks ke-2 peringkat 0, teks ke-1 peringkat 1
        $this->assertStringContainsString('data-review-rank="0"', $html);
        $this->assertStringContainsString('data-review-rank="1"', $html);
        // baris yang tidak masuk antrean bertanda -1
        $this->assertStringContainsString('data-review-rank="-1"', $html);
    }

    public function test_prediksi_tanpa_model_diberi_penanda(): void
    {
        $analysis = $this->makeAnalysis([], $this->predictions());

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertSee('Tanpa model')
            ->assertSee('Tidak dinilai');
    }

    /**
     * Seluruh array prediksi dulu ditanam ke JavaScript hanya untuk membaca
     * .length, sehingga halaman hasil untuk 885 teks mencapai 7,8 MB.
     */
    public function test_array_prediksi_tidak_ditanam_ke_javascript(): void
    {
        $predictions = [];
        for ($i = 0; $i < 40; $i++) {
            $predictions[] = [
                'text' => "kalimat asli nomor {$i} yang cukup panjang untuk terlihat pada payload",
                'processed_text' => "kalimat asli nomor {$i}",
                'original_text' => "kalimat asli nomor {$i} yang cukup panjang untuk terlihat pada payload",
                'sentiment' => 'positive',
                'confidence' => 0.99,
                'method' => 'indobert',
                'scores' => ['positive' => 0.99, 'neutral' => 0.005, 'negative' => 0.005],
                'original_index' => $i,
            ];
        }

        $analysis = $this->makeAnalysis(['total_analyzed' => 40], $predictions);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Tidak ada lagi salinan JSON prediksi di dalam halaman
        $this->assertStringNotContainsString('"scores"', $html);
        $this->assertStringNotContainsString('const allPredictions', $html);

        // Hanya satu halaman kartu yang dirender, bukan keseluruhan 40 baris
        $this->assertSame(
            \App\Services\PredictionQueryService::PER_PAGE,
            substr_count($html, 'class="prediction-card')
        );
        $this->assertStringContainsString('dari 40 prediksi', $html);
    }

    public function test_tab_tidak_muncul_kalau_tidak_ada_antrean(): void
    {
        $analysis = $this->makeAnalysis(['total_analyzed' => 4], $this->predictions());

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertDontSee('Perlu Ditinjau');
    }

    public function test_panel_filter_duplikat_tidak_lagi_dirender(): void
    {
        $predictions = [];
        for ($i = 0; $i < 12; $i++) {
            $predictions[] = [
                'text' => "kalimat {$i}",
                'sentiment' => 'positive',
                'confidence' => 0.99,
                'method' => 'indobert',
                'original_index' => $i,
            ];
        }

        $analysis = $this->makeAnalysis(['total_analyzed' => 12], $predictions);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Deklarasi ganda inilah yang dulu memicu SyntaxError di browser.
        // Sekarang array prediksi tidak ditanam ke JS sama sekali.
        $this->assertSame(0, substr_count($html, 'const allPredictions'));
        $this->assertSame(1, substr_count($html, 'id="searchPredictions"'));
        $this->assertStringContainsString('Halaman 1 dari 1', $html);
    }
}
