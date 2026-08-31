<?php

namespace Tests\Feature;

use App\Services\NLPApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Distribusi sentimen dari Python berupa persentase per batch. Agregasi yang
 * salah membuat dataset besar menghasilkan total ratusan persen.
 */
class SentimentBatchAggregationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp_api.batch_size' => 2, 'services.nlp_api.url' => 'http://nlp.test']);
    }

    private function batchResponse(array $sentiments): array
    {
        $predictions = [];
        foreach ($sentiments as $index => $sentiment) {
            $predictions[] = [
                'text' => "teks {$index}",
                'sentiment' => $sentiment,
                'confidence' => 0.9,
            ];
        }

        $total = count($sentiments);
        $distribution = [];
        foreach (['positive', 'neutral', 'negative'] as $label) {
            $distribution[$label] = round((count(array_keys($sentiments, $label)) / $total) * 100, 2);
        }

        return [
            'status' => 'success',
            'results' => [
                'predictions' => $predictions,
                'distribution' => $distribution,
                'metrics' => ['total_analyzed' => $total],
                'summary' => 'ringkasan batch',
            ],
        ];
    }

    public function test_distribusi_batch_dijumlahkan_sebagai_persentase_bukan_count(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponse(['positive', 'positive']), 200)
                ->push($this->batchResponse(['negative', 'neutral']), 200),
        ]);

        $result = (new NLPApiService())->analyzeSentiment(['a', 'b', 'c', 'd']);
        $distribution = $result['results']['distribution'];

        $this->assertEqualsWithDelta(100.0, array_sum($distribution), 0.05);
        $this->assertEqualsWithDelta(50.0, $distribution['positive'], 0.05);
        $this->assertEqualsWithDelta(25.0, $distribution['negative'], 0.05);
        $this->assertEqualsWithDelta(25.0, $distribution['neutral'], 0.05);
        $this->assertCount(4, $result['results']['predictions']);
    }

    public function test_setiap_prediksi_membawa_indeks_teks_aslinya(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponse(['positive', 'positive']), 200)
                ->push($this->batchResponse(['negative', 'neutral']), 200),
        ]);

        $result = (new NLPApiService())->analyzeSentiment(['a', 'b', 'c', 'd']);

        $this->assertSame(
            [0, 1, 2, 3],
            array_column($result['results']['predictions'], 'original_index')
        );
    }

    public function test_batch_yang_gagal_tidak_membatalkan_seluruh_analisis(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponse(['positive', 'positive']), 200)
                ->push(['detail' => 'boom'], 500)
                ->push(['detail' => 'boom'], 500)
                ->push(['detail' => 'boom'], 500),
        ]);

        $result = (new NLPApiService())->analyzeSentiment(['a', 'b', 'c', 'd']);

        $this->assertCount(2, $result['results']['predictions']);
        $this->assertEqualsWithDelta(100.0, $result['results']['distribution']['positive'], 0.05);
        $this->assertCount(1, $result['results']['metrics']['failed_batches']);
        $this->assertSame('1 dari 2 batch berhasil diproses.', $result['results']['metrics']['batch_summary']);
    }

    public function test_analisis_gagal_kalau_semua_batch_gagal(): void
    {
        Http::fake(['*/api/analyze/sentiment' => Http::response(['detail' => 'boom'], 500)]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('seluruh 2 batch tidak berhasil diproses');

        (new NLPApiService())->analyzeSentiment(['a', 'b', 'c', 'd']);
    }
}
