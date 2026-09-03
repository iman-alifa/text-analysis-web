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

        $result = (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd']);
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

        $result = (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd']);

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

        $result = (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd']);

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

        (new NLPApiService)->analyzeSentiment(['a', 'b', 'c', 'd']);
    }

    /**
     * Bangun respons batch yang memuat baris kosong bertanda `method: empty`,
     * seperti yang dikembalikan service Python untuk baris tanpa isi.
     */
    private function batchResponseWithEmpty(array $rows): array
    {
        $predictions = [];
        foreach ($rows as $index => [$sentiment, $method]) {
            $predictions[] = [
                'text' => "teks {$index}",
                'sentiment' => $sentiment,
                'confidence' => $method === 'empty' ? 0.0 : 0.9,
                'method' => $method,
            ];
        }

        return [
            'status' => 'success',
            'results' => [
                'predictions' => $predictions,
                'distribution' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                'metrics' => ['total_analyzed' => count($rows)],
                'summary' => 'ringkasan batch',
            ],
        ];
    }

    /**
     * Baris kosong tidak boleh ikut menjadi penyebut persentase.
     *
     * Service Python sudah mengeluarkannya; jalur batch di Laravel harus sama,
     * atau dataset besar melaporkan angka berbeda dari dataset kecil pada data
     * yang sama - baris kosong akan menggelembungkan kategori netral.
     */
    public function test_baris_kosong_tidak_ikut_dihitung_dalam_distribusi(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponseWithEmpty([
                    ['positive', 'indobert'], ['neutral', 'empty'],
                ]), 200)
                ->push($this->batchResponseWithEmpty([
                    ['negative', 'indobert'], ['neutral', 'empty'],
                ]), 200),
        ]);

        $result = (new NLPApiService)->analyzeSentiment(['a', '', 'c', '']);
        $results = $result['results'];

        // Dua baris yang benar-benar dinilai: satu positif, satu negatif.
        $this->assertEqualsWithDelta(50.0, $results['distribution']['positive'], 0.05);
        $this->assertEqualsWithDelta(50.0, $results['distribution']['negative'], 0.05);
        $this->assertEqualsWithDelta(0.0, $results['distribution']['neutral'], 0.05);

        $this->assertSame(4, $results['metrics']['total_texts']);
        $this->assertSame(2, $results['metrics']['total_analyzed']);
        $this->assertSame(2, $results['metrics']['total_empty']);
    }

    /**
     * Baris kosong berkeyakinan 0,0 tidak boleh menyeret rata-rata confidence
     * ke bawah; angka itu dipakai sebagai indikator mutu di halaman hasil.
     */
    public function test_confidence_rata_rata_mengabaikan_baris_kosong(): void
    {
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($this->batchResponseWithEmpty([
                    ['positive', 'indobert'], ['neutral', 'empty'],
                ]), 200)
                ->push($this->batchResponseWithEmpty([
                    ['negative', 'indobert'], ['neutral', 'empty'],
                ]), 200),
        ]);

        $result = (new NLPApiService)->analyzeSentiment(['a', '', 'c', '']);

        $this->assertEqualsWithDelta(0.9, $result['results']['metrics']['avg_confidence'], 0.001);
    }

    /**
     * Pemotongan dan kegagalan harus terlihat di metrics, bukan senyap.
     *
     * Keduanya menurunkan mutu hasil tanpa memunculkan galat apa pun: teks yang
     * melebihi 512 token kehilangan ekornya, dan teks yang gagal dinilai model
     * dikembalikan sebagai netral berkeyakinan 0. Tanpa penghitung ini,
     * pengguna tidak punya cara tahu.
     */
    public function test_pemotongan_dan_kegagalan_dilaporkan_di_metrics(): void
    {
        $buat = function (array $rows): array {
            $predictions = [];
            foreach ($rows as $index => $row) {
                $predictions[] = array_merge([
                    'text' => "teks {$index}",
                    'confidence' => 0.9,
                    'method' => 'indobert',
                ], $row);
            }

            return [
                'status' => 'success',
                'results' => [
                    'predictions' => $predictions,
                    'distribution' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                    'metrics' => ['total_analyzed' => count($rows)],
                    'summary' => 'ringkasan batch',
                ],
            ];
        };

        // batch_size = 2 (dari setUp), jadi 4 teks menempuh jalur batch.
        Http::fake([
            '*/api/analyze/sentiment' => Http::sequence()
                ->push($buat([
                    ['sentiment' => 'positive'],
                    ['sentiment' => 'negative', 'truncated' => true],
                ]), 200)
                ->push($buat([
                    ['sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'error'],
                    ['sentiment' => 'positive'],
                ]), 200),
        ]);

        $metrics = (new NLPApiService)
            ->analyzeSentiment(['a', 'b', 'c', 'd'])['results']['metrics'];

        $this->assertSame(1, $metrics['total_truncated']);
        $this->assertSame(1, $metrics['total_failed']);
        $this->assertSame(4, $metrics['total_texts']);
    }
}
