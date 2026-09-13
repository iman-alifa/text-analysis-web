<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode aspek otomatis bisa mengekstrak puluhan "aspek" tanpa daftar tetap -
 * termasuk yang cuma disebut sekali. Grafik batang bertumpuk untuk semuanya
 * sekaligus jadi sumbu-X yang penuh sesak dan tidak terbaca (terlihat pada
 * studi kasus TNI: 40+ aspek dalam satu grafik).
 */
class AspectChartLimitTest extends TestCase
{
    use RefreshDatabase;

    private function analysisWithAspects(int $jumlah): TextAnalysis
    {
        $aspectResults = [];

        for ($i = 0; $i < $jumlah; $i++) {
            // Terbalik dengan urutan penyebutan supaya tesnya membuktikan
            // pengurutan, bukan cuma kebetulan sudah terurut.
            $aspectResults[] = [
                'aspect' => "aspek{$i}",
                'count' => $jumlah - $i,
                'sentiments' => ['positive' => 50, 'neutral' => 0, 'negative' => 50],
            ];
        }

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis aspek otomatis',
            'input_type' => 'manual',
            'analysis_type' => 'aspect',
            'raw_data' => array_fill(0, $jumlah, 'x'),
            'total_records' => $jumlah,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => array_map(fn ($i) => ['text' => 'x', 'original_index' => $i, 'aspects' => ["aspek{$i}"]], range(0, $jumlah - 1)),
            'aspect_results' => $aspectResults,
        ]);

        return $analysis;
    }

    public function test_grafik_dibatasi_tetapi_kartu_menampilkan_semua(): void
    {
        config(['analysis.chart.max_aspects' => 15]);

        $analysis = $this->analysisWithAspects(40);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Isi JSON data grafik saja - bukan seluruh halaman, karena kartu
        // Detail Aspek dan tombol matanya juga menulis nama aspek ke atribut
        // HTML dan akan salah lolos/gagal kalau dicari di seluruh dokumen.
        $mulai = strpos($html, 'new Chart(aspectCtx');
        $akhir = strpos($html, 'options:', $mulai);
        $blokGrafik = substr($html, $mulai, $akhir - $mulai);

        // Grafik: hanya 15 label (ucfirst) yang dikirim ke Chart.js.
        $this->assertStringContainsString('Aspek0', $blokGrafik);
        $this->assertStringContainsString('Aspek14', $blokGrafik);
        $this->assertStringNotContainsString('Aspek15', $blokGrafik);

        // Kartu Detail Aspek tetap memuat semuanya (dipaginasi, bukan dipotong).
        // Kelasnya sendiri, bukan substring "aspect-card" - itu juga match
        // pada id kontainer dan selektor JS.
        $this->assertSame(40, substr_count($html, 'class="aspect-card '));

        $this->assertStringContainsString('Menampilkan 15 aspek dengan penyebutan terbanyak dari 40', $html);
    }

    public function test_aspek_terbanyak_ditampilkan_lebih_dulu_meski_urutan_ekstraksi_terbalik(): void
    {
        config(['analysis.chart.max_aspects' => 15]);

        $analysis = $this->analysisWithAspects(20);

        // aspek0 disimpan dengan count TERBESAR (lihat analysisWithAspects),
        // tetapi ditulis pertama dalam array mentah - membuktikan urutan
        // datang dari pengurutan count, bukan urutan penyimpanan.
        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'aspek19'),
            strpos($html, 'aspek0')
        );
    }

    public function test_tidak_ada_catatan_pemotongan_kalau_di_bawah_batas(): void
    {
        config(['analysis.chart.max_aspects' => 15]);

        $analysis = $this->analysisWithAspects(5);

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertDontSee('penyebutan terbanyak dari');
    }
}
