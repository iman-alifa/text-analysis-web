<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Jobs\RunHoldoutEvaluation;
use App\Models\EvaluationDataset;
use App\Models\ModelTraining;
use App\Services\AutoEvaluationService;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\NLPApiService;
use App\Services\RetrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Evaluasi hold-out otomatis di akhir setiap putaran pelatihan ulang.
 *
 * Loop active learning kini berjalan tanpa campur tangan: koreksi -> pelatihan
 * -> pengukuran -> putaran berikutnya. Evaluasi sempat manual atas permintaan
 * pemilik proyek; dijadikan otomatis lagi 24 Sep 2026.
 *
 * Yang dijaga: pengukuran terjadi SEKALI per putaran, di akhirnya, dan hanya
 * bila ada bobot baru yang belum diukur. Mengukur di tengah putaran berarti
 * mengukur campuran bobot baru (modul yang sudah selesai) dan lama (modul yang
 * belum), yang tidak cocok dengan iterasi mana pun.
 */
class AutoEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunHoldoutEvaluation::class]);
        config(['services.nlp_api.url' => 'http://nlp.test']);
    }

    private function datasetUjiBeku(): EvaluationDataset
    {
        $golden = app(GoldenDatasetService::class);

        $dataset = $golden->store([
            ['text' => 'Pelayanannya memuaskan sekali di kantor itu', 'gold_sentiment' => 'positive'],
            ['text' => 'Sistemnya error terus setiap kali dibuka', 'gold_sentiment' => 'negative'],
        ], ['name' => 'Hold-out', 'split' => 'test']);

        $dataset->update(['frozen_at' => now()]);

        return $dataset->fresh();
    }

    private function pelatihan(string $status, string $tipe = 'sentiment'): ModelTraining
    {
        return ModelTraining::create([
            'model_type' => $tipe,
            'status' => $status,
            'total_samples' => 60,
            'epochs' => 3,
            'learning_rate' => 0.00002,
            'completed_at' => in_array($status, ['completed', 'rejected', 'failed'], true) ? now() : null,
        ]);
    }

    private function sampel(): array
    {
        return array_map(
            fn ($i) => ['text' => "komentar nomor {$i} tentang pelayanan", 'label' => 'positive'],
            range(1, 12)
        );
    }

    // --------------------------------------------------------- ujung ke ujung

    public function test_pelatihan_yang_selesai_memicu_evaluasi_pada_test_set_beku(): void
    {
        $dataset = $this->datasetUjiBeku();

        Http::fake(['*/api/retrain/sentiment' => Http::response([
            'status' => 'success',
            'results' => ['saved' => true, 'metrics_after' => ['weighted_f1' => 0.91]],
        ], 200)]);

        $training = $this->pelatihan('pending');

        (new RetrainModel($training, $this->sampel()))->handle(app(NLPApiService::class));

        $this->assertSame('completed', $training->fresh()->status);
        Queue::assertPushed(RunHoldoutEvaluation::class, 1);
        Queue::assertPushed(
            RunHoldoutEvaluation::class,
            fn ($job) => (fn () => $this->datasetId)->call($job) === $dataset->id
        );
    }

    public function test_modul_kedua_yang_gagal_tetap_mengukur_modul_pertama(): void
    {
        // Putaran: sentimen selesai, lalu aspek gagal. Bobot sentimen yang baru
        // tetap harus diukur, jadi pemicunya berjalan juga di jalur galat.
        $this->datasetUjiBeku();
        $this->pelatihan('completed', 'sentiment');

        Http::fake(['*/api/retrain/aspect' => Http::response(['detail' => 'boom'], 500)]);

        $aspek = $this->pelatihan('pending', 'aspect');

        try {
            (new RetrainModel($aspek, array_map(
                fn ($i) => ['text' => "komentar {$i}", 'aspects' => ['pelayanan']],
                range(1, 12)
            )))->handle(app(NLPApiService::class));
        } catch (\Throwable) {
            // Job memang melempar ulang galatnya.
        }

        $this->assertSame('failed', $aspek->fresh()->status);
        Queue::assertPushed(RunHoldoutEvaluation::class, 1);
    }

    // ------------------------------------------------------------- keputusan

    public function test_tidak_mengukur_di_tengah_putaran(): void
    {
        $this->datasetUjiBeku();
        $sentimen = $this->pelatihan('completed', 'sentiment');
        $this->pelatihan('pending', 'aspect');

        $hasil = app(AutoEvaluationService::class)->setelahPelatihan($sentimen);

        $this->assertSame('putaran-belum-selesai', $hasil);
        Queue::assertNotPushed(RunHoldoutEvaluation::class);
    }

    public function test_tidak_mengukur_bila_tidak_ada_bobot_baru(): void
    {
        // Kedua modul ditolak karena metriknya menurun: bobot lama dipertahankan,
        // jadi mengukur lagi hanya menambah titik identik ke kurva.
        $this->datasetUjiBeku();
        $this->pelatihan('rejected', 'sentiment');
        $aspek = $this->pelatihan('rejected', 'aspect');

        $this->assertSame('tidak-ada-bobot-baru', app(AutoEvaluationService::class)->setelahPelatihan($aspek));
        Queue::assertNotPushed(RunHoldoutEvaluation::class);
    }

    public function test_tanpa_test_set_tidak_mengukur_dan_tidak_memblokir_putaran_berikutnya(): void
    {
        // Pemasangan baru tanpa golden dataset. Dulu penjaga "belum diukur"
        // akan menunggu evaluasi yang tidak pernah bisa dijalankan, sehingga
        // pelatihan otomatis berhenti selamanya setelah putaran pertama.
        $training = $this->pelatihan('completed');

        $this->assertSame('tidak-ada-test-set', app(AutoEvaluationService::class)->setelahPelatihan($training));
        $this->assertNull(app(RetrainingService::class)->unmeasuredTraining());
        Queue::assertNotPushed(RunHoldoutEvaluation::class);
    }

    public function test_dataset_yang_belum_beku_bukan_test_set(): void
    {
        // Dataset yang masih dianotasi belum boleh dipakai mengukur: isinya
        // masih bisa berubah, jadi dua iterasi tidak mengukur hal yang sama.
        app(GoldenDatasetService::class)->store([
            ['text' => 'Pelayanannya memuaskan sekali di kantor itu', 'gold_sentiment' => 'positive'],
        ], ['name' => 'Masih dianotasi', 'split' => 'test']);

        $this->assertNull(EvaluationDataset::ujiAktif());
    }

    public function test_bisa_dimatikan(): void
    {
        config(['analysis.evaluation.auto' => false]);
        $this->datasetUjiBeku();
        $training = $this->pelatihan('completed');

        $this->assertSame('mati', app(AutoEvaluationService::class)->setelahPelatihan($training));
        Queue::assertNotPushed(RunHoldoutEvaluation::class);
    }
}
