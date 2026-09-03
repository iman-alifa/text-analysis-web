<?php

namespace Tests\Feature;

use App\Jobs\ProcessTextAnalysis;
use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\NLPApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * review_queue menunjukkan baris mana yang paling layak dikoreksi manusia.
 *
 * Tiap batch mengirim indeks lokalnya sendiri, jadi hasil gabungan harus
 * disusun ulang memakai indeks teks asli - kalau tidak, antrean koreksi
 * menunjuk kalimat yang salah pada dataset besar.
 */
class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp_api.batch_size' => 2, 'services.nlp_api.url' => 'http://nlp.test']);
    }

    private function batchResponse(array $confidences, bool $withQueue = true): array
    {
        $predictions = [];
        foreach ($confidences as $index => $confidence) {
            $predictions[] = [
                'text' => "teks {$index}",
                'sentiment' => 'positive',
                'confidence' => $confidence,
                'method' => 'indobert',
            ];
        }

        $results = [
            'predictions' => $predictions,
            'distribution' => ['positive' => 100.0, 'neutral' => 0.0, 'negative' => 0.0],
            'metrics' => ['total_analyzed' => count($confidences)],
            'summary' => 'ringkasan',
        ];

        if ($withQueue) {
            // indeks lokal batch: selalu mulai dari 0
            $flagged = [];
            foreach ($confidences as $index => $confidence) {
                if ($confidence < 0.94) {
                    $flagged[] = $index;
                }
            }

            $results['review_queue'] = [
                'threshold' => 0.94,
                'count' => count($flagged),
                'share' => count($flagged) / max(1, count($confidences)),
                'indices' => $flagged,
            ];
        }

        return ['status' => 'success', 'results' => $results];
    }

    public function test_indeks_antrean_memakai_posisi_teks_asli_bukan_indeks_batch(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponse([0.99, 0.99]), 200)   // teks 0,1 - yakin
                ->push($this->batchResponse([0.55, 0.99]), 200)   // teks 2 ragu
                ->push($this->batchResponse([0.99, 0.30]), 200),  // teks 5 paling ragu
        ]);

        $result = (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd', 'e', 'f']);
        $queue = $result['results']['review_queue'];

        // Diurutkan dari yang paling tidak yakin: teks 5 (0.30) lalu teks 2 (0.55)
        $this->assertSame([5, 2], $queue['indices']);
        $this->assertSame(2, $queue['count']);
        $this->assertSame(0.94, $queue['threshold']);
        $this->assertEqualsWithDelta(0.3333, $queue['share'], 0.001);
    }

    public function test_baris_kosong_tidak_masuk_antrean_maupun_pembagi(): void
    {
        $response = $this->batchResponse([0.50, 0.99]);
        $response['results']['predictions'][1]['method'] = 'empty';
        $response['results']['predictions'][1]['confidence'] = 0.0;

        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($response, 200)
                ->push($this->batchResponse([0.99, 0.99]), 200),
        ]);

        $result = (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd']);
        $queue = $result['results']['review_queue'];

        $this->assertSame([0], $queue['indices']);
        // 1 dari 3 baris yang bisa dinilai, bukan 1 dari 4
        $this->assertEqualsWithDelta(0.3333, $queue['share'], 0.001);
    }

    public function test_antrean_tidak_dibuat_kalau_api_tidak_mengirim_ambang(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponse([0.50, 0.99], withQueue: false), 200)
                ->push($this->batchResponse([0.99, 0.99], withQueue: false), 200),
        ]);

        $result = (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd']);

        $this->assertArrayNotHasKey('review_queue', $result['results']);
    }

    public function test_antrean_tersimpan_bersama_metrics(): void
    {
        Http::fake([
            '*/api/warmup' => Http::response(['status' => 'success'], 200),
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponse([0.40, 0.99]), 200)
                ->push($this->batchResponse([0.99, 0.99]), 200),
        ]);

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Uji antrean',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['a', 'b', 'c', 'd'],
            'total_records' => 4,
            'status' => 'pending',
        ]);

        (new ProcessTextAnalysis($analysis))->handle(new NLPApiService);

        $metrics = AnalysisResult::where('text_analysis_id', $analysis->id)->firstOrFail()->metrics;

        $this->assertSame([0], $metrics['review_queue']['indices']);
        $this->assertSame(0.94, $metrics['review_queue']['threshold']);
    }
}
