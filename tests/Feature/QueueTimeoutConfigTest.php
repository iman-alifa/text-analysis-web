<?php

namespace Tests\Feature;

use App\Jobs\ProcessTextAnalysis;
use App\Services\NLPApiService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rantai timeout antara antrean, job, dan HTTP ke service NLP.
 *
 * Ini menutup kegagalan paling merusak yang pernah ada di sistem: `retry_after`
 * antrean (90 detik) LEBIH KECIL daripada timeout job (1800 detik), sehingga
 * worker melepaskan job yang masih berjalan kembali ke antrean dan worker kedua
 * mengambilnya. Analisis nyata memakan 95-235 detik, jadi praktis setiap
 * analisis berjalan berkali-kali sekaligus - tampak sebagai "analisis gagal"
 * atau timeout yang acak.
 */
class QueueTimeoutConfigTest extends TestCase
{
    public function test_retry_after_antrean_melebihi_timeout_job(): void
    {
        $job = new ProcessTextAnalysis(new \App\Models\TextAnalysis);
        $retryAfter = config('queue.connections.database.retry_after');

        $this->assertGreaterThan(
            $job->timeout,
            $retryAfter,
            'retry_after harus > timeout job, atau job yang masih berjalan '
            .'dilepas kembali ke antrean dan diproses ganda'
        );
    }

    public function test_timeout_http_lebih_kecil_dari_timeout_job(): void
    {
        $job = new ProcessTextAnalysis(new \App\Models\TextAnalysis);

        $this->assertLessThan(
            $job->timeout,
            config('services.nlp_api.timeout'),
            'Timeout HTTP harus < timeout job, supaya job yang menghentikan '
            .'pekerjaan macet dan bisa mencatat sebabnya'
        );
    }

    public function test_job_analisis_ditandai_unik(): void
    {
        $this->assertInstanceOf(
            ShouldBeUnique::class,
            new ProcessTextAnalysis(new \App\Models\TextAnalysis)
        );
    }

    public function test_kunci_unik_dipisah_per_analisis(): void
    {
        $satu = new \App\Models\TextAnalysis;
        $satu->id = 1;
        $dua = new \App\Models\TextAnalysis;
        $dua->id = 2;

        $this->assertNotSame(
            (new ProcessTextAnalysis($satu))->uniqueId(),
            (new ProcessTextAnalysis($dua))->uniqueId(),
            'Analisis berbeda harus tetap boleh berjalan paralel'
        );
    }

    public function test_kunci_unik_bertahan_selama_job_berjalan(): void
    {
        $job = new ProcessTextAnalysis(new \App\Models\TextAnalysis);

        $this->assertGreaterThan(
            $job->timeout,
            $job->uniqueFor,
            'Kunci unik harus hidup lebih lama daripada job, atau job kedua '
            .'bisa masuk sebelum yang pertama selesai'
        );
    }

    /**
     * Pemanasan memindahkan biaya muat model keluar dari permintaan pengguna.
     */
    public function test_warmup_memanggil_endpoint_dan_melaporkan_kesiapan(): void
    {
        Http::fake([
            '*/api/warmup' => Http::response([
                'status' => 'success',
                'models' => [
                    'sentiment' => ['loaded' => true, 'seconds' => 3.1],
                    'aspect' => ['loaded' => true, 'seconds' => 8.4],
                    'topic' => ['loaded' => true, 'seconds' => 5.2],
                ],
                'total_seconds' => 16.7,
            ], 200),
        ]);

        $this->assertTrue((new NLPApiService)->warmUp());
    }

    /**
     * Pemanasan adalah optimasi, bukan prasyarat: kegagalannya tidak boleh
     * menghentikan analisis, yang tetap bisa jalan lewat rantai cadangan.
     */
    public function test_warmup_gagal_tidak_melempar_galat(): void
    {
        Http::fake(['*/api/warmup' => Http::response('kacau', 500)]);

        $this->assertFalse((new NLPApiService)->warmUp());
    }

    public function test_warmup_melaporkan_belum_siap_bila_ada_model_gagal(): void
    {
        Http::fake([
            '*/api/warmup' => Http::response([
                'models' => [
                    'sentiment' => ['loaded' => true],
                    'aspect' => ['loaded' => false, 'error' => 'gagal unduh'],
                ],
            ], 200),
        ]);

        $this->assertFalse((new NLPApiService)->warmUp());
    }
}
