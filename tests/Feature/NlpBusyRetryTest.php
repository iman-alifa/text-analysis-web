<?php

namespace Tests\Feature;

use App\Exceptions\NlpBusyException;
use App\Jobs\ProcessTextAnalysis;
use App\Jobs\RetrainModel;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\NLPApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Analisis yang datang saat model sedang dilatih ulang.
 *
 * Layanan NLP (saklar RETRAIN_REJECT_INFERENCE) membalas 503 + `Retry-After`
 * bila model yang dibutuhkan sedang dilatih. Sisi web harus:
 *
 * 1. tidak mengulang HTTP dalam hitungan detik,
 * 2. tidak mencatatnya sebagai batch gagal (analisis selesai dengan teks
 *    hilang padahal tidak ada yang rusak),
 * 3. melepas job kembali ke antrean sebesar jeda itu TANPA menghabiskan jatah
 *    percobaan untuk galat sungguhan.
 *
 * Urutan pemasangan yang disepakati dengan nlp-api-service: perubahan ini
 * dulu, baru saklar dinyalakan. Kalau terbalik, analisis yang sekarang berhasil
 * menunggu pelatihan singkat justru gagal setelah tiga percobaan.
 */
class NlpBusyRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.nlp_api.url' => 'http://nlp.test', 'services.nlp_api.batch_size' => 2]);
        Sleep::fake();
    }

    private function sibuk(int $detik = 120): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['detail' => 'Model sentimen sedang dilatih ulang.'], 503, ['Retry-After' => (string) $detik]);
    }

    private function analisis(array $atribut = []): TextAnalysis
    {
        return TextAnalysis::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis uji',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['teks satu', 'teks dua'],
            'total_records' => 2,
            'status' => 'pending',
        ], $atribut));
    }

    // ------------------------------------------------------------ klien HTTP

    public function test_503_dengan_retry_after_tidak_diulang_dan_dilempar_sebagai_sibuk(): void
    {
        Http::fake(['*/api/analyze/sentiment' => $this->sibuk(240)]);

        try {
            app(NLPApiService::class)->analyzeSentiment(['satu', 'dua']);
            $this->fail('NlpBusyException seharusnya dilempar.');
        } catch (NlpBusyException $e) {
            $this->assertSame(240, $e->retryAfter);
        }

        // Satu permintaan saja: mengulang dalam hitungan detik hanya
        // menghasilkan 503 berikutnya.
        Http::assertSentCount(1);
    }

    public function test_503_tanpa_retry_after_tetap_galat_biasa_dan_diulang(): void
    {
        // 503 tanpa header berarti "layanan belum siap", bukan "sedang melatih".
        Http::fake(['*/api/analyze/sentiment' => Http::response(['detail' => 'belum siap'], 503)]);

        $this->expectException(RequestException::class);

        try {
            app(NLPApiService::class)->analyzeSentiment(['satu', 'dua']);
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_batch_yang_kena_sibuk_tidak_dicatat_sebagai_batch_gagal(): void
    {
        // batch_size 2, empat teks = dua batch. Batch pertama berhasil, batch
        // kedua kena pelatihan. Dulu ini akan selesai "sukses" dengan dua teks
        // hilang dan failed_batches berisi galat yang sebenarnya bukan galat.
        Http::fake(['*/api/analyze/sentiment' => Http::sequence()
            ->push(['status' => 'success', 'results' => ['predictions' => [
                ['sentiment' => 'positive', 'confidence' => 0.9],
                ['sentiment' => 'negative', 'confidence' => 0.8],
            ]]])
            ->pushResponse($this->sibuk(90)),
        ]);

        $this->expectException(NlpBusyException::class);

        app(NLPApiService::class)->analyzeSentiment(['a', 'b', 'c', 'd']);
    }

    public function test_permintaan_yang_kehabisan_waktu_tidak_diulang(): void
    {
        // Layanan tetap mengerjakan permintaan pertama walau klien memutus
        // (terukur di VPS). Mengulang berarti dua pemodelan topik serentak.
        // Dihitung di dalam callback: permintaan yang melempar tidak tercatat
        // oleh Http::assertSentCount.
        $kirim = 0;
        Http::fake(['*/api/analyze/topic' => function () use (&$kirim) {
            $kirim++;

            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 2400000 milliseconds'
            );
        }]);

        try {
            app(NLPApiService::class)->analyzeTopic(['satu', 'dua']);
            $this->fail('ConnectionException seharusnya dilempar.');
        } catch (\Illuminate\Http\Client\ConnectionException) {
        }

        $this->assertSame(1, $kirim);
    }

    public function test_koneksi_yang_gagal_tetap_diulang(): void
    {
        // Koneksi ditolak berarti layanan belum mengerjakan apa pun: aman diulang.
        $kirim = 0;
        Http::fake(['*/api/analyze/topic' => function () use (&$kirim) {
            $kirim++;

            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 7: Failed to connect to 127.0.0.1 port 8001: Connection refused'
            );
        }]);

        try {
            app(NLPApiService::class)->analyzeTopic(['satu', 'dua']);
            $this->fail('ConnectionException seharusnya dilempar.');
        } catch (\Illuminate\Http\Client\ConnectionException) {
        }

        $this->assertSame(2, $kirim);
    }

    public function test_job_yang_kehabisan_waktu_gagal_tanpa_diulang(): void
    {
        Http::fake([
            '*/api/warmup' => Http::response(['status' => 'ready'], 200),
            '*/api/analyze/topic' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out'),
        ]);

        $analisis = $this->analisis(['analysis_type' => 'topic']);
        $job = (new ProcessTextAnalysis($analisis))->withFakeQueueInteractions();

        $job->handle(app(NLPApiService::class));

        $job->assertNotReleased();
        $this->assertSame('failed', $analisis->fresh()->status);
        $this->assertStringContainsString('batas waktu', (string) $analisis->fresh()->error_message);
    }

    public function test_jeda_dibaca_dan_dibatasi(): void
    {
        $this->assertSame(120, NlpBusyException::jeda('120'));
        $this->assertSame(NlpBusyException::JEDA_MIN, NlpBusyException::jeda('0'));
        $this->assertSame(NlpBusyException::JEDA_MAKS, NlpBusyException::jeda('86400'));
        // Nilai tak terbaca jatuh ke 60 detik, bukan ke nol.
        $this->assertSame(60, NlpBusyException::jeda('sebentar'));
        // Bentuk tanggal HTTP juga sah menurut RFC 9110.
        $jeda = NlpBusyException::jeda(gmdate('D, d M Y H:i:s', time() + 300).' GMT');
        $this->assertEqualsWithDelta(300, $jeda, 2);
    }

    // ------------------------------------------------------------------ job

    public function test_job_dilepas_sebesar_retry_after_dan_tidak_ditandai_gagal(): void
    {
        Http::fake([
            '*/api/warmup' => Http::response(['status' => 'ready'], 200),
            '*/api/analyze/sentiment' => $this->sibuk(180),
        ]);

        $analisis = $this->analisis();
        $job = (new ProcessTextAnalysis($analisis))->withFakeQueueInteractions();

        $job->handle(app(NLPApiService::class));

        $job->assertReleased(180);
        $analisis->refresh();
        $this->assertNotSame('failed', $analisis->status);
        $this->assertStringContainsString('dilatih ulang', (string) $analisis->current_step);
    }

    public function test_menunggu_pelatihan_tidak_menghabiskan_jatah_galat(): void
    {
        // Setelah beberapa kali dilepas karena menunggu pelatihan, galat
        // koneksi sungguhan tetap mendapat percobaan ulang - tidak langsung
        // dinyatakan gagal karena attempts() sudah besar.
        Http::fake([
            '*/api/warmup' => Http::response(['status' => 'ready'], 200),
            '*/api/analyze/sentiment' => fn () => throw new \Illuminate\Http\Client\ConnectionException('putus'),
        ]);

        $analisis = $this->analisis();
        Cache::put('analysis-tunggu-latih:'.$analisis->id, 5, now()->addDay());

        $job = (new ProcessTextAnalysis($analisis))->withFakeQueueInteractions();
        // Percobaan ke-6 menurut Laravel; lima di antaranya menunggu pelatihan.
        $job->job->attempts = 6;

        $job->handle(app(NLPApiService::class));

        $job->assertReleased();
        $this->assertNotSame('failed', $analisis->fresh()->status);
    }

    public function test_job_memakai_jendela_waktu_bukan_hitungan_percobaan(): void
    {
        $job = new ProcessTextAnalysis(new TextAnalysis);

        $this->assertObjectNotHasProperty('tries', $job);
        $this->assertGreaterThan(now()->addHours(ProcessTextAnalysis::JENDELA_JAM - 1), $job->retryUntil());
        $this->assertTrue($job->failOnTimeout);
    }

    // ------------------------------------------------------ antrean pelatihan

    public function test_pelatihan_masuk_antrean_sendiri(): void
    {
        config(['queue.training_connection' => 'training']);

        $job = new RetrainModel(new ModelTraining, []);

        $this->assertSame('training', $job->connection);
        $this->assertSame('training', $job->queue);
    }
}
