<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairDistributionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function analysisWithDistribution(array $distribution, array $predictions): AnalysisResult
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis lama',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => [],
            'total_records' => count($predictions),
            'status' => 'completed',
        ]);

        return AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => $predictions,
            'sentiment_distribution' => $distribution,
        ]);
    }

    public function test_memperbaiki_distribusi_yang_totalnya_melebihi_seratus_persen(): void
    {
        $result = $this->analysisWithDistribution(
            ['positive' => 200.0, 'neutral' => 0.0, 'negative' => 100.0], // hasil penjumlahan 3 batch
            [
                ['text' => 'a', 'sentiment' => 'positive'],
                ['text' => 'b', 'sentiment' => 'positive'],
                ['text' => 'c', 'sentiment' => 'negative'],
                ['text' => 'd', 'sentiment' => 'negative'],
            ]
        );

        $this->artisan('analysis:repair-distribution --apply')->assertSuccessful();

        $distribution = $result->fresh()->sentiment_distribution;
        $this->assertEqualsWithDelta(50.0, $distribution['positive'], 0.05);
        $this->assertEqualsWithDelta(50.0, $distribution['negative'], 0.05);
        $this->assertEqualsWithDelta(100.0, array_sum($distribution), 0.05);
    }

    public function test_tanpa_flag_apply_data_tidak_diubah(): void
    {
        $result = $this->analysisWithDistribution(
            ['positive' => 200.0, 'neutral' => 0.0, 'negative' => 0.0],
            [['text' => 'a', 'sentiment' => 'positive'], ['text' => 'b', 'sentiment' => 'negative']]
        );

        $this->artisan('analysis:repair-distribution')->assertSuccessful();

        $this->assertEqualsWithDelta(200.0, $result->fresh()->sentiment_distribution['positive'], 0.05);
    }

    public function test_distribusi_yang_sudah_benar_dibiarkan(): void
    {
        $result = $this->analysisWithDistribution(
            ['positive' => 50.0, 'neutral' => 0.0, 'negative' => 50.0],
            [['text' => 'a', 'sentiment' => 'positive'], ['text' => 'b', 'sentiment' => 'negative']]
        );

        $this->artisan('analysis:repair-distribution --apply')->assertSuccessful();

        $this->assertEqualsWithDelta(50.0, $result->fresh()->sentiment_distribution['positive'], 0.05);
    }
}
