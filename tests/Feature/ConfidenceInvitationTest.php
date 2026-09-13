<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\ConfidenceReportService;
use App\Support\TextFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pengguna diberi tahu seberapa yakin model pada kasusnya, lalu ditawari ikut
 * mengoreksi baris yang paling meragukan.
 *
 * Dihitung di Laravel dari `predictions`, bukan hanya dari
 * `metrics.review_queue` milik NLP API: pada basis data proyek ini
 * `review_queue` kosong untuk seluruh analisis yang sudah ada, sehingga
 * ajakannya tidak akan pernah muncul kalau bergantung pada blok itu.
 */
class ConfidenceInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('nlp.review_threshold');

        // Bawaan tes: tanpa pengaturan eksplisit DAN tanpa toleransi galat,
        // supaya sisa rantai prioritas (hasil analisis > layanan > bawaan) bisa
        // diuji satu per satu. Jalur normalnya justru toleransi galat, yang
        // diuji tersendiri di bawah.
        config()->set('analysis.confidence.review_threshold', null);
        config()->set('analysis.confidence.error_tolerance', null);
        config()->set('analysis.confidence.fallback_threshold', 0.94);
        config()->set('analysis.confidence.band_error_levels', [0.10, 0.30]);
    }

    /**
     * @param  array<int, float|null>  $keyakinan
     */
    private function analysis(array $keyakinan, array $metrics = []): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Opini warga',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => array_map(fn ($i) => "kalimat {$i}", array_keys($keyakinan)),
            'total_records' => count($keyakinan),
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => array_map(fn ($nilai, $i) => array_filter([
                'original_index' => $i,
                // TrainingItemService menuntut kunci 'text'; prediksi asli
                // memuat keduanya.
                'text' => "kalimat {$i}",
                'original_text' => "kalimat {$i}",
                'sentiment' => 'neutral',
                'confidence' => $nilai,
            ], fn ($v) => $v !== null), $keyakinan, array_keys($keyakinan)),
            'sentiment_distribution' => ['positive' => 0.0, 'neutral' => 100.0, 'negative' => 0.0],
            'metrics' => $metrics,
        ]);

        return $analysis->fresh('result');
    }

    // -------------------------------------------------------------- hitungan

    public function test_merangkum_keyakinan_dari_prediksi(): void
    {
        // Ambang disetel eksplisit: yang diuji di sini hitungannya, bukan
        // bagaimana ambangnya ditentukan.
        config()->set('analysis.confidence.review_threshold', 0.70);

        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.95, 0.90, 0.60, 0.50])->result);

        $this->assertSame(4, $laporan['scored']);
        $this->assertSame(0.7375, $laporan['average']);
        $this->assertSame(2, $laporan['low_count']);
        $this->assertSame(50.0, $laporan['low_share']);
    }

    public function test_baris_tanpa_keyakinan_tidak_menurunkan_rerata(): void
    {
        // Baris kosong dan baris yang gagal dinilai tidak punya keyakinan;
        // menghitungnya sebagai nol akan membuat mutunya tampak jauh lebih buruk.
        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.90, 0.90, null])->result);

        $this->assertSame(2, $laporan['scored']);
        $this->assertSame(1, $laporan['unscored']);
        $this->assertSame(0.9, $laporan['average']);
    }

    public function test_baris_method_empty_tidak_dihitung_sebagai_sangat_ragu(): void
    {
        // method=empty (teks emotikon/kosong) tersimpan dengan confidence 0.0 -
        // angka pengisi, BUKAN keyakinan model sungguhan (lihat NLPApiService).
        // is_numeric(0.0) bernilai true, jadi pemeriksaan lama meloloskannya dan
        // menghitungnya sebagai "sangat ragu" - menggelembungkan ajakan active
        // learning jauh melebihi baris yang benar-benar ditawarkan di halaman
        // koreksi (yang sudah menyaring method=empty lewat TextScorability).
        config()->set('analysis.confidence.review_threshold', 0.70);

        $user = User::factory()->create();
        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Opini warga dengan emotikon',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['bagus sekali', ':)', 'lumayan', '😀😀'],
            'total_records' => 4,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                ['original_index' => 0, 'text' => 'bagus sekali', 'sentiment' => 'positive', 'confidence' => 0.95, 'method' => 'indobert'],
                ['original_index' => 1, 'text' => ':)', 'sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'empty'],
                ['original_index' => 2, 'text' => 'lumayan', 'sentiment' => 'neutral', 'confidence' => 0.40, 'method' => 'indobert'],
                ['original_index' => 3, 'text' => '😀😀', 'sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'empty'],
            ],
            'sentiment_distribution' => ['positive' => 25.0, 'neutral' => 75.0, 'negative' => 0.0],
        ]);

        $laporan = app(ConfidenceReportService::class)->build($analysis->fresh('result')->result);

        // Hanya 2 baris yang benar-benar dinilai model; 2 baris emotikon
        // disingkirkan sepenuhnya, bukan ikut dihitung sebagai "sangat ragu".
        $this->assertSame(2, $laporan['scored']);
        $this->assertSame(2, $laporan['unscored']);
        $this->assertSame(1, $laporan['bands']['rendah']['count']);
    }

    public function test_ajakan_di_halaman_hasil_sama_dengan_hitungan_halaman_koreksi(): void
    {
        // Inti perbaikan: jumlah yang dijanjikan ajakan active learning di
        // halaman hasil harus sama persis dengan jumlah baris yang benar-benar
        // ditawarkan di halaman koreksi - keduanya harus memakai definisi
        // "bisa dinilai" yang sama.
        config()->set('analysis.confidence.review_threshold', 0.70);

        $user = User::factory()->create();
        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Opini warga dengan emotikon',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['bagus sekali', ':)', 'lumayan', '😀😀'],
            'total_records' => 4,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                ['original_index' => 0, 'text' => 'bagus sekali', 'sentiment' => 'positive', 'confidence' => 0.95, 'method' => 'indobert'],
                ['original_index' => 1, 'text' => ':)', 'sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'empty'],
                ['original_index' => 2, 'text' => 'lumayan', 'sentiment' => 'neutral', 'confidence' => 0.40, 'method' => 'indobert'],
                ['original_index' => 3, 'text' => '😀😀', 'sentiment' => 'neutral', 'confidence' => 0.0, 'method' => 'empty'],
            ],
            'sentiment_distribution' => ['positive' => 25.0, 'neutral' => 75.0, 'negative' => 0.0],
        ]);

        $halamanHasil = $this->actingAs($user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1 teks dinilai dengan sangat ragu', $halamanHasil);

        $halamanKoreksi = $this->actingAs($user)
            ->get(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'sangat-ragu']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Sangat ragu (1)', $halamanKoreksi);
        $this->assertStringContainsString('lumayan', $halamanKoreksi);
        $this->assertStringNotContainsString(':)', $halamanKoreksi);
        $this->assertStringNotContainsString('😀😀', $halamanKoreksi);
    }

    public function test_baris_yang_dipakai_golden_dataset_tidak_dihitung(): void
    {
        // Baris yang jadi kandidat golden dataset (reservedHashes) tidak pernah
        // ditawarkan di halaman koreksi - lihat AnalysisFeedbackController.
        // Tanpa penyaringan yang sama di sini, ajakan di halaman hasil
        // menjanjikan angka yang lebih besar daripada yang benar-benar bisa
        // dikoreksi.
        config()->set('analysis.confidence.review_threshold', 0.70);

        $analysis = $this->analysis([0.95, 0.85, 0.60, 0.40]);

        $dataset = EvaluationDataset::create(['name' => 'Golden uji', 'split' => 'test']);
        EvaluationItem::create([
            'evaluation_dataset_id' => $dataset->id,
            'text' => 'kalimat 3',
            'text_hash' => TextFingerprint::hash('kalimat 3'),
        ]);

        $laporan = app(ConfidenceReportService::class)->build($analysis->result);

        // Tanpa "kalimat 3" (0,40): scored turun dari 4 ke 3, dan pita
        // "Sangat ragu" turun dari 1 ke 0.
        $this->assertSame(3, $laporan['scored']);
        $this->assertSame(0, $laporan['bands']['rendah']['count']);
        // Bukan "tidak dinilai" - baris ini benar-benar dinilai model, hanya
        // sedang dipakai sebagai data uji.
        $this->assertSame(0, $laporan['unscored']);
    }

    public function test_pengaturan_eksplisit_mengalahkan_segalanya(): void
    {
        // Kalau nilai temuan otomatis yang menang, mengisi .env tidak
        // berpengaruh apa-apa - dan itu jauh lebih membingungkan daripada
        // sekadar berbeda dari layanan.
        config()->set('analysis.confidence.review_threshold', 0.80);
        Http::fake(['*/health' => Http::response(['sentiment_review_threshold' => 0.90])]);

        $analysis = $this->analysis([0.95, 0.85, 0.60], [
            'review_queue' => ['threshold' => 0.70, 'count' => 1],
        ]);

        $laporan = app(ConfidenceReportService::class)->build($analysis->result);

        $this->assertSame(0.8, $laporan['threshold']);
        $this->assertSame('konfigurasi', $laporan['threshold_source']);
        $this->assertSame(1, $laporan['low_count']);
    }

    public function test_tanpa_pengaturan_ambang_hasil_analisis_dipakai(): void
    {
        // Itulah angka yang menghasilkan review_queue tersimpan; memakai angka
        // lain akan berselisih dengannya.
        $analysis = $this->analysis([0.95, 0.85], [
            'review_queue' => ['threshold' => 0.90, 'count' => 1],
        ]);

        $laporan = app(ConfidenceReportService::class)->build($analysis->result);

        $this->assertSame(0.9, $laporan['threshold']);
        $this->assertSame('nlp-api', $laporan['threshold_source']);
    }

    public function test_ambang_diambil_dari_layanan_untuk_analisis_lama(): void
    {
        // Analisis yang dijalankan sebelum review_queue disimpan tidak punya
        // ambangnya sendiri. Tanpa menanyakan layanan, Laravel memakai angkanya
        // sendiri dan melaporkan jumlah "baris ragu" yang berbeda untuk data
        // yang sama - terukur 20 versus 42 baris pada analisis 69.
        // Bentuk sebenarnya: ambangnya berada di dalam blok `models`.
        Http::fake(['*/health' => Http::response([
            'models' => ['sentiment_review_threshold' => 0.90],
        ])]);

        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.95, 0.85, 0.60])->result);

        $this->assertSame(0.9, $laporan['threshold']);
        $this->assertSame('nlp-api', $laporan['threshold_source']);
        $this->assertSame(2, $laporan['low_count']);
    }

    public function test_layanan_mati_hanya_dihubungi_sekali(): void
    {
        // Cache::remember memperlakukan null sebagai cache miss, sehingga
        // layanan yang mati akan dihubungi ulang - dengan timeout 10 detik -
        // setiap kali halaman hasil dibuka.
        Http::fake(['*/health' => Http::response([], 503)]);

        $analysis = $this->analysis([0.95, 0.60]);
        $service = app(ConfidenceReportService::class);

        $service->build($analysis->result);
        $service->build($analysis->result);
        $service->build($analysis->result);

        Http::assertSentCount(1);
    }

    public function test_bawaan_dipakai_bila_layanan_tidak_terjangkau(): void
    {
        Http::fake(['*/health' => Http::response([], 503)]);

        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.95, 0.85])->result);

        // 0,94 adalah nilai efektif layanan untuk bobot yang aktif, bukan 0,90.
        $this->assertSame(0.94, $laporan['threshold']);
        $this->assertSame('bawaan', $laporan['threshold_source']);
    }

    public function test_ambang_tidak_masuk_akal_dari_api_diabaikan(): void
    {
        Http::fake(['*/health' => Http::response([], 503)]);

        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.80], ['review_queue' => ['threshold' => 1.5]])->result);

        $this->assertSame(0.94, $laporan['threshold']);
    }

    public function test_ambang_diturunkan_dari_toleransi_galat(): void
    {
        // Jalur normal. Ambang = 1 - toleransi, jadi 0,10 berarti 0,90.
        //
        // Dasarnya bukan penyetelan: model dikalibrasi suhu (ECE 0,0209),
        // sehingga keyakinan adalah taksiran peluang benar dan ambang menjadi
        // pernyataan risiko - tidak menyentuh data uji sama sekali, jadi tidak
        // ada masalah selection-on-test.
        config()->set('analysis.confidence.error_tolerance', 0.10);

        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.95, 0.85])->result);

        $this->assertSame(0.9, $laporan['threshold']);
        $this->assertSame('toleransi-galat', $laporan['threshold_source']);
    }

    public function test_batas_pita_teratas_selalu_sama_dengan_ambang(): void
    {
        // Inti keselarasannya. Dulu pita berdiri sendiri (0,85) sementara ambang
        // tinjauan 0,80, sehingga satu teks bisa disebut "cukup yakin" sekaligus
        // "perlu ditinjau" di panel yang sama.
        foreach ([0.10, 0.05, 0.20] as $toleransi) {
            config()->set('analysis.confidence.error_tolerance', $toleransi);

            $laporan = app(ConfidenceReportService::class)
                ->build($this->analysis([0.99, 0.92, 0.86, 0.75, 0.60])->result);

            $this->assertSame(
                $laporan['threshold'],
                $laporan['band_bounds']['tinggi'],
                'batas pita teratas harus persis sama dengan ambang tinjauan'
            );

            // Himpunan "di luar pita teratas" dan "perlu ditinjau" harus identik.
            $this->assertSame(
                $laporan['low_count'],
                $laporan['bands']['sedang']['count'] + $laporan['bands']['rendah']['count'],
            );
        }
    }

    public function test_penilaian_ikut_bergeser_saat_ambang_berubah(): void
    {
        // Dulu verdict memakai 0,90 dan 0,80 sendiri, jadi mengubah ambang tidak
        // mengubah penilaiannya sama sekali.
        $nilai = [0.93, 0.93, 0.93, 0.93, 0.93];

        config()->set('analysis.confidence.error_tolerance', 0.10);   // ambang 0,90
        $longgar = app(ConfidenceReportService::class)->build($this->analysis($nilai)->result);

        config()->set('analysis.confidence.error_tolerance', 0.02);   // ambang 0,98
        $ketat = app(ConfidenceReportService::class)->build($this->analysis($nilai)->result);

        $this->assertSame('Sangat baik', $longgar['verdict']['label']);
        $this->assertNotSame('Sangat baik', $ketat['verdict']['label']);
    }

    public function test_pita_keyakinan_dihitung(): void
    {
        $laporan = app(ConfidenceReportService::class)
            ->build($this->analysis([0.95, 0.80, 0.50])->result);

        $this->assertSame(1, $laporan['bands']['tinggi']['count']);
        $this->assertSame(1, $laporan['bands']['sedang']['count']);
        $this->assertSame(1, $laporan['bands']['rendah']['count']);
    }

    public function test_penilaian_memakai_rerata_dan_bagian_yang_ragu(): void
    {
        // Rerata tinggi saja tidak cukup: korpus dengan banyak baris ragu
        // punya kebutuhan berbeda walau reratanya sama.
        $bersih = app(ConfidenceReportService::class)
            ->build($this->analysis(array_fill(0, 20, 0.99))->result);

        $bercampur = app(ConfidenceReportService::class)
            ->build($this->analysis([0.30, 0.35, 0.40, 0.45, 0.50])->result);

        $this->assertSame('Sangat baik', $bersih['verdict']['label']);
        $this->assertSame('Perlu perhatian', $bercampur['verdict']['label']);
    }

    public function test_indeks_baris_ragu_diurutkan_dari_yang_paling_ragu(): void
    {
        $analysis = $this->analysis([0.95, 0.40, 0.65, 0.20]);

        $this->assertSame(
            [3, 1, 2],
            app(ConfidenceReportService::class)->lowConfidenceIndices($analysis->result)
        );
    }

    public function test_analisis_tanpa_keyakinan_tidak_menghasilkan_laporan(): void
    {
        $this->assertNull(
            app(ConfidenceReportService::class)->build($this->analysis([null, null])->result)
        );
    }

    // -------------------------------------------------------------- tampilan

    public function test_halaman_hasil_menampilkan_keyakinan_dan_ajakan(): void
    {
        config()->set('analysis.confidence.review_threshold', 0.70);

        // Ambang 0,70 -> batas pita "Sangat ragu" jatuh ke 0,70-0,20=0,50
        // (lihat batasPita: pita atas selalu dipaksa sama dengan ambang,
        // jadi batas kedua dari band_error_levels bawaan (0,70) akan
        // bertabrakan dan digeser). Hanya 0,40 yang masuk pita itu; 0,60
        // masuk pita "Ragu" yang lebih longgar dan TIDAK diminta oleh ajakan.
        $analysis = $this->analysis([0.95, 0.85, 0.60, 0.40]);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Seberapa Yakin Model pada Data Anda?', $html);
        $this->assertStringContainsString('70.0%', $html);

        // Ajakan HANYA meminta pita "Sangat ragu" (1 baris), bukan seluruh
        // "Ragu" (2 baris: 0,60 dan 0,40) sekaligus.
        $this->assertStringContainsString('1 teks dinilai dengan sangat ragu', $html);
        $this->assertStringContainsString('Koreksi 1 baris yang sangat ragu', $html);
        $this->assertStringContainsString('fokus=sangat-ragu', $html);
        // Ajakannya harus jelas bersifat sukarela.
        $this->assertStringContainsString('Bersifat sukarela', $html);
    }

    public function test_tidak_mengajak_bila_tidak_ada_baris_ragu(): void
    {
        $analysis = $this->analysis([0.95, 0.99]);

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertSee('ditinjau lebih dulu')
            ->assertDontSee('yang ragu</a>', false);
    }

    public function test_tidak_mengajak_kalau_hanya_ada_baris_ragu_tanpa_yang_sangat_ragu(): void
    {
        // 0,60 masuk pita "Ragu" (0,50-0,70), tetapi tidak ada satu pun di
        // bawah 0,50 ("Sangat ragu"). Ajakan active learning harus diam -
        // ia cuma meminta pita paling meragukan, bukan pita "Ragu" yang
        // lebih longgar.
        config()->set('analysis.confidence.review_threshold', 0.70);

        $analysis = $this->analysis([0.95, 0.85, 0.60, 0.55]);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ditinjau lebih dulu', $html);
        $this->assertStringNotContainsString('dinilai dengan sangat ragu', $html);
    }

    // --------------------------------------------------------- fokus koreksi

    public function test_halaman_koreksi_bisa_difokuskan_ke_sangat_ragu(): void
    {
        config()->set('analysis.confidence.review_threshold', 0.70);

        // Batas "Sangat ragu" jatuh ke 0,50 (lihat catatan pada tes ajakan di
        // atas). Hanya kalimat 3 (0,40) yang lebih ragu dari itu; kalimat 2
        // (0,60) masuk pita "Ragu" yang lebih longgar dan tidak boleh ikut.
        $analysis = $this->analysis([0.95, 0.85, 0.60, 0.40]);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'sangat-ragu']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('kalimat 3', $html);
        $this->assertStringNotContainsString('kalimat 2', $html);
        $this->assertStringNotContainsString('kalimat 0', $html);
        $this->assertStringContainsString('Sangat ragu (1)', $html);
        // Pita "Ragu" yang lebih luas tetap bisa dibuka dari halaman koreksi.
        $this->assertStringContainsString('Baris yang ragu (2)', $html);
        $this->assertStringContainsString('Semua baris (4)', $html);
    }

    public function test_fokus_sangat_ragu_terbawa_setelah_menyimpan_koreksi(): void
    {
        $analysis = $this->analysis([0.95, 0.30]);

        // Baris koreksi baru diekstrak dari hasil JSON saat halaman koreksi
        // pertama kali dibuka.
        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'sangat-ragu']))
            ->assertOk();

        $item = $analysis->trainingItems()->orderBy('confidence_score')->first();

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'fokus' => 'sangat-ragu',
                'corrections' => [['item_id' => $item->id, 'corrected_sentiment' => 'negative']],
            ])
            ->assertRedirect(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'sangat-ragu']));
    }

    public function test_halaman_koreksi_bisa_difokuskan_ke_baris_ragu(): void
    {
        config()->set('analysis.confidence.review_threshold', 0.70);

        $analysis = $this->analysis([0.95, 0.90, 0.60, 0.50]);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'ragu']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('kalimat 3', $html);
        $this->assertStringContainsString('kalimat 2', $html);
        // Baris yang sudah yakin tidak ikut ditampilkan.
        $this->assertStringNotContainsString('kalimat 0', $html);
        $this->assertStringContainsString('Baris yang ragu (2)', $html);
        $this->assertStringContainsString('Semua baris (4)', $html);
    }

    public function test_tanpa_fokus_seluruh_baris_tampil(): void
    {
        $analysis = $this->analysis([0.95, 0.90, 0.60, 0.50]);

        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->assertSee('kalimat 0')
            ->assertSee('kalimat 3');
    }

    public function test_fokus_terbawa_setelah_menyimpan_koreksi(): void
    {
        $analysis = $this->analysis([0.95, 0.50]);

        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'ragu']))
            ->assertOk();

        $item = $analysis->trainingItems()->orderBy('confidence_score')->first();

        // Pengguna yang datang dari ajakan tidak boleh terlempar ke daftar penuh.
        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'fokus' => 'ragu',
                'corrections' => [['item_id' => $item->id, 'corrected_sentiment' => 'negative']],
            ])
            ->assertRedirect(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'ragu']));
    }

    public function test_kemajuan_koreksi_ditampilkan(): void
    {
        $analysis = $this->analysis([0.95, 0.50, 0.40, 0.30]);

        $this->actingAs($analysis->user)->get(route('analysis.feedback', $analysis->id))->assertOk();

        $analysis->trainingItems()->limit(1)->update([
            'corrected_sentiment' => 'negative',
            'is_corrected' => true,
            'verified_by' => $analysis->user_id,
            'verified_at' => now(),
        ]);

        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->assertSee('1 dari 4 baris sudah dikoreksi');
    }

    public function test_fokus_karangan_diperlakukan_sebagai_semua(): void
    {
        $analysis = $this->analysis([0.95, 0.50]);

        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', ['id' => $analysis->id, 'fokus' => 'karangan']))
            ->assertOk()
            ->assertSee('kalimat 0');
    }
}
