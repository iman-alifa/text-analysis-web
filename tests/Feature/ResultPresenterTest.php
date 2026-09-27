<?php

namespace Tests\Feature;

use App\Helpers\ResultPresenter;
use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kartu metrik dulu menebak format dari tipe data PHP: setiap float dikalikan
 * 100 lalu diberi tanda persen. Akibatnya `negative_percentage = 88.58` tampil
 * sebagai "8858%" pada analisis 69 dan 72, sementara analisis 95 - yang
 * menyimpan angka yang sama sebagai integer 60 - tampil "60" tanpa persen.
 */
class ResultPresenterTest extends TestCase
{
    use RefreshDatabase;

    /** Metrik apa adanya dari analisis 69 di basis data pengembangan. */
    private function metrics(): array
    {
        return [
            'total_texts' => 289,
            'avg_confidence' => 0.9479,
            'max_confidence' => 0.9983,
            'min_confidence' => 0.3939,
            'total_analyzed' => 289,
            'pipeline_version' => 2,
            'neutral_percentage' => 6.23,
            'negative_percentage' => 88.58,
            'positive_percentage' => 5.19,
        ];
    }

    private function nilai(array $baris, string $key): ?string
    {
        foreach ($baris as $item) {
            if ($item['key'] === $key) {
                return $item['value'];
            }
        }

        return null;
    }

    public function test_persentase_tidak_dikalikan_seratus_lagi(): void
    {
        $teknis = ResultPresenter::technicalMetrics($this->metrics());

        $this->assertSame('88.6%', $this->nilai($teknis, 'negative_percentage'));
    }

    public function test_pecahan_keyakinan_dijadikan_persen(): void
    {
        $kartu = ResultPresenter::metricCards($this->metrics());

        $this->assertSame('94.8%', $this->nilai($kartu, 'avg_confidence'));
        $this->assertSame('39.4%', $this->nilai($kartu, 'min_confidence'));
    }

    public function test_int_dan_float_diperlakukan_sama(): void
    {
        // Analisis 95 menyimpan persentase sebagai integer, analisis 69 sebagai
        // float. Keduanya harus tampil dengan cara yang sama.
        $dariInt = ResultPresenter::technicalMetrics(['negative_percentage' => 60]);
        $dariFloat = ResultPresenter::technicalMetrics(['negative_percentage' => 60.0]);

        $this->assertSame('60.0%', $this->nilai($dariInt, 'negative_percentage'));
        $this->assertSame($this->nilai($dariInt, 'negative_percentage'), $this->nilai($dariFloat, 'negative_percentage'));
    }

    public function test_kartu_utama_hanya_empat_metrik_yang_berarti(): void
    {
        $kartu = ResultPresenter::metricCards($this->metrics());

        $this->assertCount(4, $kartu);
        $this->assertSame('289', $this->nilai($kartu, 'total_texts'));
        // Catatan internal tidak layak jadi kartu besar.
        $this->assertNull($this->nilai($kartu, 'pipeline_version'));
    }

    public function test_metrik_tak_dikenal_tetap_tampil_apa_adanya(): void
    {
        // Lebih baik terlihat mentah daripada terlihat rapi tetapi salah -
        // itu justru asal mula bug 8858%.
        $teknis = ResultPresenter::technicalMetrics(['metrik_baru' => 0.42]);

        $this->assertSame('0.42', $this->nilai($teknis, 'metrik_baru'));
    }

    public function test_nilai_bersarang_dilewati(): void
    {
        // review_queue dan failed_batches adalah array; mencetaknya lewat {{ }}
        // dulu membuat seluruh halaman hasil gagal render.
        $teknis = ResultPresenter::technicalMetrics([
            'review_queue' => ['count' => 3, 'indices' => [1, 2, 3]],
            'total_texts' => 10,
        ]);

        $this->assertNull($this->nilai($teknis, 'review_queue'));
    }

    public function test_istilah_indonesia(): void
    {
        $this->assertSame('Positif', ResultPresenter::sentimentLabel('positive'));
        $this->assertSame('Netral', ResultPresenter::sentimentLabel('neutral'));
        $this->assertSame('Analisis Gabungan', ResultPresenter::analysisTypeLabel('combined'));
        $this->assertSame('Topic Modeling', ResultPresenter::analysisTypeLabel('topic'));
    }

    // ------------------------------------------------------------- tampilan

    private function analysis(): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Opini warga',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => ['a', 'b'],
            'total_records' => 2,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                ['original_text' => 'tarifnya mahal', 'sentiment' => 'negative', 'confidence' => 0.9],
            ],
            'sentiment_distribution' => ['positive' => 5.19, 'neutral' => 6.23, 'negative' => 88.58],
            'aspect_results' => [
                ['aspect' => 'tarif', 'count' => 333, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
            ],
            'topic_results' => ['topics' => [['topic_id' => 0, 'words' => ['tarif'], 'proportion' => 0.6]]],
            'metrics' => $this->metrics(),
            'summary' => 'Ringkasan sistem.',
        ]);

        return $analysis->fresh('result');
    }

    public function test_halaman_hasil_tidak_lagi_mencetak_persentase_yang_dikalikan_seratus(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('8858', $html);
        $this->assertStringContainsString('Teks Dianalisis', $html);
        $this->assertStringContainsString('Rata-rata Keyakinan', $html);
        // Catatan internal dilipat, bukan dibuang.
        $this->assertStringContainsString('Detail teknis', $html);
    }

    public function test_halaman_hasil_memakai_istilah_indonesia(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Analisis Gabungan', $html);
        $this->assertStringContainsString('penyebutan', $html);
        $this->assertStringNotContainsString('mentions', $html);
    }

    public function test_grafik_punya_padanan_teks(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // <canvas> tanpa label hanya elemen kosong bagi pembaca layar.
        $this->assertStringContainsString('Diagram distribusi sentimen: Positif 5.19 persen', $html);
        $this->assertStringContainsString('role="img"', $html);
    }

    public function test_navigasi_hanya_menunjuk_bagian_yang_dirender(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Setiap tautan navigasi harus punya jangkar yang benar-benar ada.
        preg_match_all('/data-nav-bagian="([^"]+)"/', $html, $tautan);
        $this->assertNotEmpty($tautan[1]);

        foreach (array_unique($tautan[1]) as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "Jangkar {$id} tidak ada di halaman.");
        }
    }
}
