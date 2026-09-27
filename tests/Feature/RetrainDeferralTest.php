<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Jobs\RunHoldoutEvaluation;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\RetrainDeferral;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\AutoRetrainService;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\RetrainDeferralService;
use App\Services\RetrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mode tunda pelatihan untuk fase evaluasi kuesioner, dan pemutaran ulangnya.
 *
 * Yang dijaga:
 *
 * 1. Selama mode tunda, pemicu otomatis DICATAT, tidak dijalankan - dan
 *    dihitung persis seperti pemicu yang akan terjadi (satu per kumpulan
 *    koreksi yang melewati ambang), bukan satu per koreksi.
 * 2. Pemutaran ulang melatih kolam yang DIPOTONG pada saat pemicunya. Tanpa
 *    itu N putaran melatih kolam akhir yang sama dan hasilnya identik.
 * 3. Putaran berikutnya baru berjalan setelah putaran sebelumnya terukur.
 */
class RetrainDeferralTest extends TestCase
{
    use RefreshDatabase;

    private TextAnalysis $analisis;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RetrainModel::class, RunHoldoutEvaluation::class]);

        config([
            'analysis.auto_retrain.enabled' => true,
            'analysis.auto_retrain.min_new_corrections' => 10,
            'analysis.auto_retrain.cooldown_hours' => 0,
            'analysis.evaluation.auto' => true,
        ]);

        $this->analisis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Uji coba pengguna',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => 'completed',
        ]);
    }

    private function koreksi(int $jumlah, $waktu, string $awalan = 'k'): void
    {
        static $nomor = 0;

        foreach (range(1, $jumlah) as $i) {
            $nomor++;
            TrainingItem::create([
                'text_analysis_id' => $this->analisis->id,
                'text_content' => "komentar {$awalan} nomor {$nomor} tentang pelayanan publik",
                'predicted_sentiment' => 'neutral',
                'predicted_method' => 'indobert',
                'confidence_score' => 0.5,
                'corrected_sentiment' => 'positive',
                'is_corrected' => true,
                'verified_at' => $waktu,
            ]);
        }
    }

    private function datasetBerbaseline(): EvaluationDataset
    {
        $dataset = app(GoldenDatasetService::class)->store([
            ['text' => 'Pelayanannya memuaskan sekali di kantor itu', 'gold_sentiment' => 'positive'],
            ['text' => 'Sistemnya error terus setiap kali dibuka', 'gold_sentiment' => 'negative'],
        ], ['name' => 'Golden kuesioner', 'split' => 'test']);

        $dataset->update(['frozen_at' => now()->subMinute()]);

        EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => 0,
            'completed_at' => now(),
            'metrics' => ['sentiment' => ['accuracy' => 80]],
        ]);

        return $dataset->fresh();
    }

    // -------------------------------------------------------------- tunda

    public function test_pemicu_dicatat_bukan_dijalankan(): void
    {
        app(RetrainDeferralService::class)->setAktif(true);
        $this->koreksi(60, now());

        $hasil = app(AutoRetrainService::class)->run($this->analisis->id);

        $this->assertFalse($hasil['triggered']);
        $this->assertFalse($hasil['blocking'], 'Penundaan bukan hal yang perlu diberitahukan kepada pengguna.');
        $this->assertSame(0, ModelTraining::count());
        Queue::assertNotPushed(RetrainModel::class);

        $tunda = RetrainDeferral::sole();
        $this->assertSame(60, $tunda->new_corrections);
        $this->assertSame(60, $tunda->pool_size);
        $this->assertSame('tertunda', $tunda->status);
    }

    public function test_satu_pemicu_per_kumpulan_koreksi_bukan_per_koreksi(): void
    {
        // Setelah pemicu pertama, hitungan koreksi baru mulai lagi dari titik
        // tunda itu - persis seperti bila pelatihannya berjalan.
        app(RetrainDeferralService::class)->setAktif(true);
        $auto = app(AutoRetrainService::class);

        $this->koreksi(60, now()->subMinutes(10));
        $auto->run();

        $this->travel(1)->minutes();
        $this->koreksi(5, now());
        $auto->run();
        $this->assertSame(1, RetrainDeferral::count(), '5 koreksi baru belum melewati ambang 10.');

        $this->travel(1)->minutes();
        $this->koreksi(10, now());
        $auto->run();
        $this->assertSame(2, RetrainDeferral::count());
        $this->assertSame(15, RetrainDeferral::latest('id')->first()->new_corrections);
    }

    public function test_tombol_admin_juga_ditahan_kecuali_dipaksa(): void
    {
        app(RetrainDeferralService::class)->setAktif(true);
        $this->koreksi(60, now());

        $hasil = app(RetrainingService::class)->dispatch('both', 3, 1, $this->analisis->id);

        $this->assertStringContainsString('Mode tunda', (string) $hasil['error']);
        $this->assertSame(0, ModelTraining::count());
    }

    public function test_kolam_dipotong_pada_waktu_pemicu(): void
    {
        $this->koreksi(60, now()->subHour());
        $this->koreksi(40, now());

        $layanan = app(RetrainingService::class);

        $this->assertCount(60, $layanan->correctedItemsSampai(now()->subMinutes(30)));
        $this->assertCount(100, $layanan->correctedItems());
    }

    // --------------------------------------------------------- putar ulang

    public function test_putar_ulang_menunggu_golden_dataset_dan_baseline(): void
    {
        app(RetrainDeferralService::class)->catat(60);
        $layanan = app(RetrainDeferralService::class);

        $this->assertStringContainsString('golden dataset beku', implode(' ', $layanan->syaratPutarUlang()));

        $dataset = app(GoldenDatasetService::class)->store([
            ['text' => 'Pelayanannya memuaskan sekali di kantor itu', 'gold_sentiment' => 'positive'],
        ], ['name' => 'Golden', 'split' => 'test']);
        $dataset->update(['frozen_at' => now()]);

        $this->assertStringContainsString('belum diukur', implode(' ', $layanan->syaratPutarUlang()));
    }

    public function test_putar_ulang_tidak_boleh_saat_mode_tunda_masih_aktif(): void
    {
        $this->datasetBerbaseline();
        $layanan = app(RetrainDeferralService::class);
        $layanan->setAktif(true);
        $layanan->catat(60);

        $this->assertStringContainsString('Mode tunda masih aktif', implode(' ', $layanan->syaratPutarUlang()));
    }

    public function test_putaran_diputar_berurutan_dengan_kolam_saat_itu(): void
    {
        $layanan = app(RetrainDeferralService::class);

        // Pemicu 1 pada kolam 60, pemicu 2 pada kolam 90.
        $this->koreksi(60, now()->subHours(3));
        $this->travelTo(now()->subHours(2));
        $pertama = $layanan->catat(60);
        $this->travelBack();

        $this->koreksi(30, now()->subHour());
        $this->travelTo(now()->subMinutes(30));
        $kedua = $layanan->catat(30);
        $this->travelBack();

        // Koreksi SESUDAH pemicu terakhir tidak boleh ikut putaran mana pun.
        $this->koreksi(25, now());

        $this->datasetBerbaseline();
        $layanan->mulai();

        // Putaran 1: hanya kolam sampai pemicu 1.
        $this->assertSame('dijalankan', $pertama->fresh()->status);
        $this->assertSame('tertunda', $kedua->fresh()->status);
        $latih1 = ModelTraining::where('model_type', 'sentiment')->sole();
        $this->assertSame(60, $latih1->total_samples);

        // Putaran 1 selesai tetapi belum terukur: putaran 2 menunggu.
        $latih1->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame('menunggu-evaluasi', $layanan->lanjutkan());

        // Setelah terukur, putaran 2 berjalan atas kolam sampai pemicu 2.
        $this->travel(1)->minutes();
        EvaluationRun::create([
            'evaluation_dataset_id' => EvaluationDataset::ujiAktif()->id,
            'status' => 'completed',
            'iteration' => 1,
            'completed_at' => now(),
            'metrics' => ['sentiment' => ['accuracy' => 82]],
        ]);

        $this->assertSame('diputar', $layanan->lanjutkan());
        $this->assertSame('dijalankan', $kedua->fresh()->status);
        $this->assertSame(90, ModelTraining::where('model_type', 'sentiment')->latest('id')->first()->total_samples);

        // Tidak ada lagi yang tertunda: rantai berhenti sendiri.
        ModelTraining::query()->update(['status' => 'completed', 'completed_at' => now()]);
        $this->travel(1)->minutes();
        EvaluationRun::create([
            'evaluation_dataset_id' => EvaluationDataset::ujiAktif()->id,
            'status' => 'completed',
            'iteration' => 2,
            'completed_at' => now(),
            'metrics' => ['sentiment' => ['accuracy' => 84]],
        ]);

        $this->assertSame('selesai', $layanan->lanjutkan());
        $this->assertFalse(RetrainDeferralService::putarUlangAktif());
    }

    public function test_koreksi_sesudah_pemicu_tetap_terhitung_baru_setelah_diputar_ulang(): void
    {
        // Kejadian di server 24-26 Sep: pemicu #1 tercatat 12:48 (79 koreksi),
        // 36 koreksi menyusul sampai 15:xx, lalu pemicu itu diputar ulang 26 Sep.
        // Pelatihan hasil pemutaran ulang hanya melatih kolam sampai 12:48,
        // jadi ke-36 koreksi itu masih "baru" - tetapi dulu hitungannya dimulai
        // dari waktu pelatihan dibuat (26 Sep) dan mereka tidak pernah terhitung.
        config(['analysis.auto_retrain.cooldown_hours' => 6]);
        $layanan = app(RetrainDeferralService::class);
        $awal = now()->subDays(3);

        $this->koreksi(79, $awal);
        $this->travelTo($awal->copy()->addMinutes(48));
        $pemicu = $layanan->catat(79);
        $this->travelBack();

        $this->koreksi(36, $awal->copy()->addHours(3));

        $this->datasetBerbaseline();
        $layanan->mulai();

        $this->assertSame('dijalankan', $pemicu->fresh()->status);
        $this->assertSame(79, ModelTraining::where('model_type', 'sentiment')->sole()->total_samples);

        $auto = app(AutoRetrainService::class);
        $this->assertSame(36, $auto->newCorrectionCount());

        // Jeda tetap memakai waktu pelatihan sebenarnya, bukan batas kolamnya.
        $this->assertGreaterThan(0, $auto->cooldownRemaining());
    }

    public function test_batas_kolam_pelatihan_langsung_adalah_waktu_pembuatannya(): void
    {
        $this->koreksi(60, now()->subHours(2));

        ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'completed',
            'total_samples' => 60,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ])->forceFill(['created_at' => now()->subHour()])->save();

        $this->koreksi(12, now()->subMinutes(30));

        $this->assertSame(12, app(AutoRetrainService::class)->newCorrectionCount());
    }

    public function test_pemicu_dengan_kolam_terlalu_kecil_dilewati(): void
    {
        // Pada titik pemicu pertama kolamnya baru 20 (< MIN_LAYAK 50). Bila
        // berjalan langsung pun pemicu itu akan ditolak, jadi dilewati dan
        // pemicu berikutnya langsung dicoba.
        $layanan = app(RetrainDeferralService::class);

        $this->koreksi(20, now()->subHours(3));
        $this->travelTo(now()->subHours(2));
        $kecil = $layanan->catat(20);
        $this->travelBack();

        $this->koreksi(40, now()->subHour());
        $this->travelTo(now()->subMinutes(30));
        $layak = $layanan->catat(40);
        $this->travelBack();

        $this->datasetBerbaseline();
        $layanan->mulai();

        $this->assertSame('dilewati', $kecil->fresh()->status);
        $this->assertSame('dijalankan', $layak->fresh()->status);
        $this->assertSame(60, ModelTraining::where('model_type', 'sentiment')->sole()->total_samples);
    }

    public function test_pemicu_langsung_diam_selama_putar_ulang(): void
    {
        $layanan = app(RetrainDeferralService::class);
        $this->koreksi(60, now()->subHour());
        $this->travelTo(now()->subMinutes(30));
        $layanan->catat(60);
        $this->travelBack();

        $this->datasetBerbaseline();
        $layanan->mulai();

        $this->koreksi(60, now());
        $hasil = app(AutoRetrainService::class)->run();

        $this->assertFalse($hasil['triggered']);
        $this->assertStringContainsString('Pemutaran ulang', $hasil['reason']);
    }

    // ------------------------------------------------------------- admin

    public function test_panel_dan_saklar_di_halaman_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.training.index'))
            ->assertOk()
            ->assertSee('Fase Evaluasi Kuesioner')
            ->assertSee('Nyalakan mode tunda');

        $this->actingAs($admin)->post(route('admin.training.tunda'), ['aktif' => 1])->assertRedirect();
        $this->assertTrue(RetrainDeferralService::aktif());

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->post(route('admin.training.tunda'), ['aktif' => 0])
            ->assertForbidden();
        $this->assertTrue(RetrainDeferralService::aktif());
    }

    public function test_perintah_status(): void
    {
        $this->artisan('retrain:tunda', ['aksi' => 'nyalakan'])->assertSuccessful();
        $this->assertTrue(RetrainDeferralService::aktif());

        $this->artisan('retrain:tunda')
            ->expectsOutputToContain('AKTIF')
            ->assertSuccessful();
    }
}
