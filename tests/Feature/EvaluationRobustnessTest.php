<?php

namespace Tests\Feature;

use App\Jobs\RunHoldoutEvaluation;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\HoldoutEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * Evaluasi hold-out tidak boleh berakhir dengan angka yang salah tanpa jejak,
 * dan tidak boleh meninggalkan run yang "berjalan" selamanya.
 *
 * Asalnya kejadian di server 26 Sep: dua batch aspek kehabisan waktu (600 dtk),
 * job evaluasi dibunuh setelah 31 menit, dan run-nya tertinggal 'running'.
 * Kalau job-nya tidak mati, evaluasi itu akan selesai dengan baris dari batch
 * yang gagal terbaca sebagai "tidak ada aspek" - recall yang salah, tanpa jejak.
 */
class EvaluationRobustnessTest extends TestCase
{
    use RefreshDatabase;

    /** Permintaan sentimen/aspek yang sampai ke layanan palsu. */
    private int $permintaan = 0;

    // ---------------------------------------------------------- W1: run yatim

    public function test_job_yang_dihentikan_menandai_run_miliknya_gagal(): void
    {
        $dataset = $this->dataset(2);
        $lain = $this->dataset(2, 'Dataset lain');

        // failed() berjalan pada salinan job yang di-unserialize, bukan pada
        // objek aslinya - persis seperti di worker.
        $job = unserialize(serialize(new RunHoldoutEvaluation($dataset->id)));

        $this->travel(1)->seconds();
        $milikJob = $this->runBerjalan($dataset);
        $bukanMilikJob = $this->runBerjalan($lain);

        $job->failed(new \RuntimeException('App\Jobs\RunHoldoutEvaluation has timed out.'));

        $milikJob->refresh();
        $this->assertSame('failed', $milikJob->status);
        $this->assertStringContainsString('timed out', $milikJob->error_message);
        $this->assertStringContainsString('Jalankan ulang', $milikJob->error_message);
        $this->assertNotNull($milikJob->completed_at);

        $this->assertSame('running', $bukanMilikJob->fresh()->status);
    }

    public function test_run_yang_selesai_tidak_disentuh_saat_job_gagal(): void
    {
        $dataset = $this->dataset(2);
        $job = new RunHoldoutEvaluation($dataset->id);

        $selesai = EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => 0,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $job->failed(new \RuntimeException('apa saja'));

        $this->assertSame('completed', $selesai->fresh()->status);
    }

    // ------------------------------------------------- W2: hasil parsial ditolak

    public function test_satu_batch_sentimen_gagal_menggagalkan_run_tanpa_metrik(): void
    {
        config(['services.nlp_api.batch_size' => 2]);
        $dataset = $this->dataset(6);

        // Batch 2 dari 3 kehabisan waktu, seperti di server.
        $this->fakeLayanan(sentimenGagalPada: 2);

        $run = $this->jalankan($dataset);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Batch sentimen 2 dari 3 gagal', $run->error_message);
        $this->assertNull($run->metrics);
        $this->assertSame(0, $run->items()->count());

        // Berhenti di batch yang gagal: batch 3 tidak pernah dikirim, jadi
        // worker bersama tidak tertahan untuk pekerjaan yang toh ditolak.
        $this->assertSame(2, $this->permintaan);
    }

    public function test_satu_batch_aspek_gagal_menggagalkan_run_tanpa_metrik(): void
    {
        config(['services.nlp_api.batch_size' => 2]);
        $dataset = $this->dataset(4, aspek: true);

        $this->fakeLayanan(aspekGagalPada: 2);

        $run = $this->jalankan($dataset);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Batch aspek 2 dari 2 gagal', $run->error_message);
        $this->assertNull($run->metrics);
    }

    public function test_baris_yang_gagal_dinilai_layanan_bukan_prediksi(): void
    {
        $dataset = $this->dataset(2);

        // Layanan mengisi teks yang gagal dinilai dengan `neutral` keyakinan 0
        // dan `method: error`. Itu bukan tebakan model.
        $this->fakeLayanan(prediksi: [
            ['sentiment' => 'positive', 'confidence' => 0.97, 'method' => 'model'],
            ['sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'error'],
        ]);

        $run = $this->jalankan($dataset);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('1 dari 2 baris tidak mendapat prediksi sentimen yang sah (baris ke-2)', $run->error_message);
        $this->assertNull($run->metrics);
    }

    public function test_prediksi_yang_kurang_dari_jumlah_baris_ditolak(): void
    {
        $dataset = $this->dataset(3);

        $this->fakeLayanan(prediksi: [
            ['sentiment' => 'positive', 'confidence' => 0.97],
            ['sentiment' => 'negative', 'confidence' => 0.91],
        ]);

        $run = $this->jalankan($dataset);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('baris ke-3', $run->error_message);
    }

    public function test_evaluasi_utuh_tetap_selesai(): void
    {
        config(['services.nlp_api.batch_size' => 2]);
        $dataset = $this->dataset(4, aspek: true);

        $this->fakeLayanan();

        $run = $this->jalankan($dataset);

        $this->assertSame('completed', $run->status);
        $this->assertSame(4, $run->items()->count());
        $this->assertNotNull($run->metrics['aspect']['micro_f1'] ?? null);
    }

    public function test_analisis_pengguna_tetap_memaafkan_batch_yang_gagal(): void
    {
        // Penolakan hanya berlaku untuk evaluasi. Analisis pengguna tetap
        // selesai dan melaporkan batch yang gagal.
        config(['services.nlp_api.batch_size' => 2]);
        $this->fakeLayanan(sentimenGagalPada: 2);

        $hasil = app(\App\Services\NLPApiService::class)->analyzeSentiment(
            ['satu satu', 'dua dua', 'tiga tiga', 'empat empat', 'lima lima', 'enam enam']
        );

        $this->assertCount(4, $hasil['results']['predictions']);
        $this->assertCount(1, $hasil['results']['metrics']['failed_batches']);
    }

    // ------------------------------------------------------------------ util

    private function jalankan(EvaluationDataset $dataset): EvaluationRun
    {
        try {
            app(HoldoutEvaluationService::class)->run($dataset, ['with_topic' => false]);
        } catch (Throwable) {
            // Yang diperiksa adalah baris run-nya, bukan pengecualiannya.
        }

        return EvaluationRun::latest('id')->firstOrFail();
    }

    private function runBerjalan(EvaluationDataset $dataset): EvaluationRun
    {
        return EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'running',
            'iteration' => 0,
            'started_at' => now(),
        ]);
    }

    private function dataset(int $jumlah, string $nama = 'Golden uji', bool $aspek = false): EvaluationDataset
    {
        $baris = [];

        for ($i = 1; $i <= $jumlah; $i++) {
            $baris[] = [
                'text' => "Komentar nomor {$i} tentang pelayanan kantor itu",
                'gold_sentiment' => $i % 2 ? 'positive' : 'negative',
                'gold_aspects' => $aspek ? ['pelayanan'] : null,
            ];
        }

        $dataset = app(GoldenDatasetService::class)->store($baris, ['name' => $nama, 'split' => 'test']);

        if ($aspek) {
            $dataset->items()->update(['gold_aspects_normalized' => json_encode(['layan'])]);
        }

        $dataset->update(['frozen_at' => now()]);

        return $dataset->fresh();
    }

    /**
     * Layanan palsu yang menjawab sesuai jumlah teks di tiap permintaan.
     * Batch yang "gagal" melempar cURL 28, persis galat di log server.
     */
    private function fakeLayanan(
        ?int $sentimenGagalPada = null,
        ?int $aspekGagalPada = null,
        ?array $prediksi = null,
    ): void {
        Http::preventStrayRequests();

        $batchSentimen = 0;
        $batchAspek = 0;

        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'models' => []], 200),
            '*/api/analyze/sentiment' => function ($request) use (&$batchSentimen, $sentimenGagalPada, $prediksi) {
                $this->permintaan++;
                $batchSentimen++;

                if ($batchSentimen === $sentimenGagalPada) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 600001 milliseconds');
                }

                $teks = $request->data()['texts'];

                return Http::response(['status' => 'success', 'results' => [
                    'predictions' => $prediksi ?? array_map(
                        fn ($t) => ['text' => $t, 'sentiment' => 'positive', 'confidence' => 0.95, 'method' => 'model'],
                        $teks
                    ),
                ]], 200);
            },
            '*/api/analyze/aspect' => function ($request) use (&$batchAspek, $aspekGagalPada) {
                $this->permintaan++;
                $batchAspek++;

                if ($batchAspek === $aspekGagalPada) {
                    throw new ConnectionException('cURL error 28: Operation timed out after 600001 milliseconds');
                }

                $jumlah = count($request->data()['texts']);

                return Http::response(['status' => 'success', 'results' => [
                    'aspect_sentiments' => [['aspect' => 'pelayanan', 'count' => $jumlah, 'sentiments' => ['positive' => 100, 'neutral' => 0, 'negative' => 0]]],
                    'document_aspects' => array_fill(0, $jumlah, ['pelayanan']),
                    'document_aspects_normalized' => array_fill(0, $jumlah, ['layan']),
                ]], 200);
            },
        ]);
    }
}
