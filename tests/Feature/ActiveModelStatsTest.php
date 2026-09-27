<?php

namespace Tests\Feature;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\User;
use App\Services\ActiveModelService;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\HoldoutEvaluationService;
use App\Services\PublicStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Angka yang ditampilkan adalah angka model YANG SEDANG DIPAKAI, bukan angka
 * run evaluasi terakhir.
 *
 * Skenarionya meniru server 27 Sep: sentimen aktif = iterasi 2, aspek aktif =
 * iterasi 1 karena aspek iterasi 2 lebih buruk dan bobotnya dikembalikan. Run
 * terakhir (iterasi 2) mengukur bobot aspek yang sudah diarsipkan.
 */
class ActiveModelStatsTest extends TestCase
{
    use RefreshDatabase;

    private EvaluationDataset $dataset;

    /** @var array<string, ModelTraining> */
    private array $latih = [];

    /** @var array<string, EvaluationRun> */
    private array $run = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->dataset = EvaluationDataset::create([
            'name' => 'Golden kuesioner',
            'split' => 'test',
            'item_count' => 300,
            'frozen_at' => Carbon::parse('2026-09-24 20:00'),
        ]);

        // Iterasi 0: bobot dasar.
        $this->run['r10'] = $this->runEvaluasi(0, '2026-09-25 08:00', 80.0, 60.0);

        // Iterasi 1.
        $this->latih['s16'] = $this->pelatihan('sentiment', 'sent-iter1', '2026-09-25 12:00');
        $this->latih['a17'] = $this->pelatihan('aspect', 'aspek-iter1', '2026-09-25 12:30');
        $this->run['r11'] = $this->runEvaluasi(1, '2026-09-25 13:00', 83.0, 80.33);

        // Iterasi 2: aspeknya lebih buruk.
        $this->latih['s18'] = $this->pelatihan('sentiment', 'sent-iter2', '2026-09-26 16:00');
        $this->latih['a19'] = $this->pelatihan('aspect', 'aspek-iter2', '2026-09-26 16:20');
        $this->run['r14'] = $this->runEvaluasi(2, '2026-09-27 07:00', 84.5, 78.77);
    }

    public function test_bobot_yang_dipulihkan_memakai_angka_run_yang_mengukurnya(): void
    {
        $this->layanan(['sentiment_pool_fingerprint' => 'sent-iter2', 'aspect_pool_fingerprint' => 'aspek-iter1']);

        $s = app(PublicStatsService::class)->snapshot();

        // Sentimen dari run 14 (iterasi 2), aspek dari run 11 (iterasi 1).
        $this->assertEquals(84.5, $s['accuracy']);
        $this->assertSame(2, $s['accuracy_iteration']);
        $this->assertEquals(80.33, $s['aspect_f1']);
        $this->assertSame(1, $s['aspect_iteration']);
        $this->assertEquals(60.0, $s['aspect_f1_baseline']);
        $this->assertSame(2, $s['iterations']);

        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringContainsString('80,33%', $html);
        $this->assertStringNotContainsString('78,77', $html);
        $this->assertStringContainsString('Model aspek yang sedang dipakai berasal dari iterasi 1', $html);
        $this->assertStringContainsString('Model sentimen yang sedang dipakai berasal dari iterasi 2', $html);
    }

    public function test_run_lama_tanpa_sidik_jari_memakai_pelatihan_sebelum_run_dimulai(): void
    {
        $model = app(ActiveModelService::class);

        $this->assertSame('dasar', $model->kunciRun($this->run['r10'], 'aspect'));
        $this->assertSame('sj:aspek-iter1', $model->kunciRun($this->run['r11'], 'aspect'));
        $this->assertSame('sj:aspek-iter2', $model->kunciRun($this->run['r14'], 'aspect'));
        $this->assertSame('sj:sent-iter2', $model->kunciRun($this->run['r14'], 'sentiment'));
    }

    public function test_run_baru_dengan_sidik_jari_mengalahkan_aturan_cadangan(): void
    {
        // Diukur ulang setelah pemulihan: model_info menyebut bobot aspek
        // iterasi 1, walaupun pelatihan aspek terakhir sebelum run ini #19.
        $baru = $this->runEvaluasi(2, '2026-09-27 12:00', 84.5, 80.40, [
            'sentiment_pool_fingerprint' => 'sent-iter2',
            'aspect_pool_fingerprint' => 'aspek-iter1',
        ]);

        $this->layanan(['sentiment_pool_fingerprint' => 'sent-iter2', 'aspect_pool_fingerprint' => 'aspek-iter1']);

        $this->assertSame('sj:aspek-iter1', app(ActiveModelService::class)->kunciRun($baru, 'aspect'));
        $this->assertEquals(80.40, app(PublicStatsService::class)->snapshot()['aspect_f1']);
    }

    public function test_bobot_aktif_yang_belum_diukur_tidak_memakai_angka_run_lain(): void
    {
        // Pelatihan aspek baru sudah terpasang tetapi belum dievaluasi.
        $this->pelatihan('aspect', 'aspek-iter3', '2026-09-27 09:00');
        $this->layanan(['sentiment_pool_fingerprint' => 'sent-iter2', 'aspect_pool_fingerprint' => 'aspek-iter3']);

        $s = app(PublicStatsService::class)->snapshot();

        $this->assertNull($s['aspect_f1']);
        $this->assertEquals(84.5, $s['accuracy']);

        $html = $this->get(route('landing'))->assertOk()->getContent();
        $this->assertStringNotContainsString('78,77', $html);
        $this->assertStringNotContainsString('80,33', $html);
    }

    public function test_layanan_tidak_terjangkau_memakai_bacaan_terakhir_dan_halaman_tetap_terbuka(): void
    {
        $this->layanan(['sentiment_pool_fingerprint' => 'sent-iter2', 'aspect_pool_fingerprint' => 'aspek-iter1']);
        app(PublicStatsService::class)->snapshot();

        // Layanan mati setelahnya; cache segar sudah kedaluwarsa.
        $this->lupakanCache();
        Http::fake(['*/health' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);

        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('80,33%', $html);
        $this->assertStringNotContainsString('78,77', $html);
        $this->assertSame('cache', app(ActiveModelService::class)->aktif()['sumber']);
    }

    public function test_layanan_tidak_pernah_terjangkau_tetap_tidak_menjatuhkan_halaman(): void
    {
        Http::fake(['*/health' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);

        $this->get(route('landing'))->assertOk();
        $this->assertFalse(app(ActiveModelService::class)->aktif()['pasti']);
    }

    public function test_halaman_admin_menandai_pelatihan_dan_run_yang_aktif(): void
    {
        $this->layanan(['sentiment_pool_fingerprint' => 'sent-iter2', 'aspect_pool_fingerprint' => 'aspek-iter1']);

        $aktif = app(ActiveModelService::class)->pelatihanAktif();
        $this->assertSame($this->latih['s18']->id, $aktif['sentiment']->id);
        $this->assertSame($this->latih['a17']->id, $aktif['aspect']->id);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.evaluation.show', $this->dataset->id))
            ->assertOk()
            ->assertViewHas('runAktif', fn (array $run) => ($run[$this->run['r11']->id] ?? []) === ['aspect']
                && ($run[$this->run['r14']->id] ?? []) === ['sentiment']
                && ! isset($run[$this->run['r10']->id]));

        $this->actingAs($admin)
            ->get(route('admin.training.index'))
            ->assertOk()
            ->assertViewHas('pelatihanAktif', fn (array $p) => $p === [
                'sentiment' => $this->latih['s18']->id,
                'aspect' => $this->latih['a17']->id,
            ]);
    }

    public function test_run_evaluasi_menyimpan_sidik_jari_bobot_yang_diukur(): void
    {
        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store([
            ['text' => 'Pelayanannya memuaskan sekali', 'gold_sentiment' => 'positive'],
        ], ['name' => 'Golden kecil', 'split' => 'test']);
        $dataset->update(['frozen_at' => now()]);

        // sentimen masih bobot dasar (kunci ada, nilainya null).
        $this->layanan([
            'sentiment_pool_fingerprint' => null,
            'sentiment_pool_samples' => null,
            'aspect_pool_fingerprint' => 'aspek-iter1',
            'aspect_pool_samples' => 450,
            'aspect_restored_from' => 'aspek-iter2',
        ], [
            '*/api/analyze/sentiment' => Http::response(['status' => 'success', 'results' => [
                'predictions' => [['sentiment' => 'positive', 'confidence' => 0.97, 'method' => 'model']],
            ]], 200),
        ]);

        $run = app(HoldoutEvaluationService::class)->run($dataset->fresh(), ['with_topic' => false]);

        $this->assertArrayHasKey('sentiment_pool_fingerprint', $run->model_info);
        $this->assertNull($run->model_info['sentiment_pool_fingerprint']);
        $this->assertSame('aspek-iter1', $run->model_info['aspect_pool_fingerprint']);
        $this->assertSame(450, $run->model_info['aspect_pool_samples']);
        $this->assertSame('aspek-iter2', $run->model_info['aspect_restored_from']);

        $model = app(ActiveModelService::class);
        $this->assertSame('dasar', $model->kunciRun($run, 'sentiment'));
        $this->assertSame('sj:aspek-iter1', $model->kunciRun($run, 'aspect'));
    }

    // ------------------------------------------------------------------ util

    private function layanan(array $models, array $tambahan = []): void
    {
        $this->lupakanCache();

        Http::fake($tambahan + [
            '*/health' => Http::response(['status' => 'healthy', 'models' => $models], 200),
        ]);
    }

    private function lupakanCache(): void
    {
        app(PublicStatsService::class)->forget();
        Cache::forget(ActiveModelService::CACHE_SEGAR);
    }

    private function pelatihan(string $modul, string $sidik, string $selesai): ModelTraining
    {
        $pelatihan = ModelTraining::create([
            'model_type' => $modul,
            'status' => 'completed',
            'total_samples' => 450,
            'epochs' => 3,
            'learning_rate' => 0.00002,
            'result' => ['sidik_jari_kolam' => $sidik, 'saved' => true],
            'completed_at' => Carbon::parse($selesai),
        ]);

        $pelatihan->forceFill(['created_at' => Carbon::parse($selesai)->subHour()])->save();

        return $pelatihan;
    }

    private function runEvaluasi(int $iterasi, string $mulai, float $akurasi, float $f1Parsial, array $modelInfo = []): EvaluationRun
    {
        return EvaluationRun::create([
            'evaluation_dataset_id' => $this->dataset->id,
            'status' => 'completed',
            'iteration' => $iterasi,
            'started_at' => Carbon::parse($mulai),
            'completed_at' => Carbon::parse($mulai)->addMinutes(6),
            'model_info' => $modelInfo,
            'metrics' => [
                'sentiment' => ['n' => 300, 'accuracy' => $akurasi, 'accuracy_ci' => ['low' => 70.0, 'high' => 90.0]],
                'aspect' => ['available' => true, 'micro_f1' => $f1Parsial - 3, 'partial' => ['micro_f1' => $f1Parsial]],
            ],
        ]);
    }
}
