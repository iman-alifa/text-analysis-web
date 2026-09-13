<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Models\AnalysisResult;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\RetrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tombol kirim harus mengirim SELURUH koreksi satu analisis.
 *
 * Perilaku lama hanya mengirim baris pada halaman paginasi yang sedang tampil.
 * Terukur pada studi kasus: 218 koreksi tersimpan di basis data, hanya 19 yang
 * benar-benar dilatihkan - 9%. Sisanya tersimpan sebagai draf di peramban dan
 * tidak pernah sampai ke server.
 *
 * Dua jebakan pada jalur yang sama, dan keduanya diam:
 *
 * 1. Formulir hanya memuat medan untuk 25 baris yang dirender.
 * 2. Empat medan x 262 koreksi = 1.048 variabel, sementara `max_input_vars`
 *    PHP bawaannya 1.000 - sisanya dibuang tanpa galat apa pun. Karena itu
 *    kirimannya berupa satu medan JSON, bukan medan per baris.
 */
class WholeAnalysisCorrectionTest extends TestCase
{
    use RefreshDatabase;

    /** Lebih besar dari PER_PAGE (25) supaya paginasi benar-benar terlibat. */
    private const JUMLAH = 60;

    private function analysis(int $jumlah = self::JUMLAH): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Tentara Nasional Indonesia',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => range(1, $jumlah),
            'total_records' => $jumlah,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => array_map(fn ($i) => [
                'original_index' => $i,
                'text' => "komentar warga nomor {$i} tentang pelayanan lembaga",
                'sentiment' => 'neutral',
                'confidence' => 0.5,
                'method' => 'indobert',
                'aspects' => ['pelayanan'],
            ], range(0, $jumlah - 1)),
            'sentiment_distribution' => ['positive' => 0.0, 'neutral' => 100.0, 'negative' => 0.0],
        ]);

        $analysis = $analysis->fresh('result');

        // Halaman koreksi memicu ekstraksi baris latih.
        $this->actingAs($user)->get(route('analysis.feedback', $analysis->id))->assertOk();

        return $analysis;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function koreksiUntuk(TextAnalysis $analysis, string $sentimen = 'positive'): array
    {
        return $analysis->trainingItems()
            ->orderBy('id')
            ->get()
            ->map(fn (TrainingItem $item) => [
                'item_id' => $item->id,
                'corrected_sentiment' => $sentimen,
                'corrected_aspects' => 'pelayanan',
                'correction_notes' => null,
            ])
            ->all();
    }

    public function test_seluruh_koreksi_satu_analisis_tersimpan_dalam_satu_kiriman(): void
    {
        $analysis = $this->analysis();

        $this->assertSame(self::JUMLAH, $analysis->trainingItems()->count());

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode($this->koreksiUntuk($analysis)),
            ])
            ->assertRedirect();

        // Inti perbaikannya: 60 baris, bukan 25 baris satu halaman.
        $this->assertSame(
            self::JUMLAH,
            $analysis->trainingItems()->where('is_corrected', true)->count()
        );
    }

    public function test_kiriman_melewati_batas_max_input_vars_php(): void
    {
        // 262 koreksi x 4 medan = 1.048 variabel form; max_input_vars bawaannya
        // 1.000. Sebagai medan formulir biasa, sisanya dibuang PHP tanpa galat.
        // Sebagai satu medan JSON, batas itu tidak tersentuh sama sekali.
        $analysis = $this->analysis(300);

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode($this->koreksiUntuk($analysis)),
            ])
            ->assertRedirect();

        $this->assertSame(300, $analysis->trainingItems()->where('is_corrected', true)->count());
    }

    public function test_id_yang_tersimpan_dibalikkan_untuk_membersihkan_draf(): void
    {
        // Draf peramban hanya boleh dihapus untuk baris yang BENAR-BENAR
        // tersimpan. Menghapusnya optimistis saat menekan Kirim berarti
        // kiriman yang gagal di tengah jalan menghilangkan pekerjaan pengguna.
        $analysis = $this->analysis(5);
        $items = $analysis->trainingItems()->orderBy('id')->get();

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode([
                    ['item_id' => $items[0]->id, 'corrected_sentiment' => 'positive'],
                    // Tidak disentuh sama sekali - server melewatinya, jadi
                    // drafnya TIDAK boleh ikut dihapus.
                    ['item_id' => $items[1]->id, 'corrected_sentiment' => null],
                ]),
            ])
            ->assertRedirect()
            ->assertSessionHas('koreksi_tersimpan', [$items[0]->id]);
    }

    public function test_draf_baris_yang_tak_mungkin_tersimpan_ikut_dibersihkan(): void
    {
        // Baris milik analisis lain tidak akan pernah bisa disimpan di sini.
        // Kalau drafnya tidak ikut dibuang, tombol Kirim menyala selamanya dan
        // pengguna terus disuguhi "tidak ada yang disimpan" tanpa tahu sebabnya.
        $analysis = $this->analysis(5);
        $lain = $this->analysis(5);

        $asing = $lain->trainingItems()->first()->id;
        $milik = $analysis->trainingItems()->first()->id;

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode([
                    ['item_id' => $milik, 'corrected_sentiment' => 'positive'],
                    ['item_id' => $asing, 'corrected_sentiment' => 'negative'],
                ]),
            ])
            ->assertRedirect()
            ->assertSessionHas('koreksi_tersimpan', [$milik, $asing]);
    }

    public function test_baris_milik_analisis_lain_tidak_ikut_tersimpan(): void
    {
        $analysis = $this->analysis(5);
        $lain = $this->analysis(5);

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode([
                    ['item_id' => $lain->trainingItems()->first()->id, 'corrected_sentiment' => 'negative'],
                ]),
            ])
            ->assertRedirect();

        $this->assertSame(0, $lain->trainingItems()->where('is_corrected', true)->count());
    }

    public function test_jalur_tanpa_javascript_tetap_diterima(): void
    {
        // `corrections[]` biasa tetap jalan untuk peramban tanpa JavaScript.
        // Di jalur itu jumlahnya selalu satu halaman paginasi, jadi aman dari
        // batas max_input_vars.
        $analysis = $this->analysis(5);
        $item = $analysis->trainingItems()->first();

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections' => [
                    ['item_id' => $item->id, 'corrected_sentiment' => 'negative'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('negative', $item->fresh()->corrected_sentiment);
    }

    // -------------------------------------------------- protokol kolam

    /**
     * Menyiapkan dua analisis berkoreksi, dikirim lewat tombol Kirim sungguhan.
     *
     * @return array{0: TextAnalysis, 1: TextAnalysis}
     */
    private function duaAnalisisTerkoreksi(int $jumlah = 30): array
    {
        $a = $this->analysis($jumlah);
        $b = $this->analysis($jumlah);

        $this->actingAs($a->user)->post(route('analysis.feedback.store', $a->id), [
            'corrections_json' => json_encode($this->koreksiUntuk($a)),
        ]);
        $this->actingAs($b->user)->post(route('analysis.feedback.store', $b->id), [
            'corrections_json' => json_encode($this->koreksiUntuk($b, 'negative')),
        ]);

        return [$a, $b];
    }

    public function test_pelatihan_selalu_memakai_seluruh_kolam(): void
    {
        // Inti protokol active learning baku. Tiap putaran berangkat dari bobot
        // dasar tetap (`retrain_from_baseline` di sisi layanan) atas SELURUH
        // label yang sudah terkumpul. Kalau di sini disaring per analisis,
        // putaran kedua berangkat dari bobot dasar tetapi hanya melihat lembaga
        // kedua - lembaga pertama terlupakan seluruhnya.
        [$a, $b] = $this->duaAnalisisTerkoreksi();

        $retraining = app(RetrainingService::class);

        $this->assertCount(60, $retraining->correctedItems());

        // Sumbangan per lembaga tetap bisa dibaca - untuk pelaporan, lewat
        // method yang terpisah supaya tidak bisa tak sengaja dipakai menyaring.
        $this->assertCount(30, $retraining->contributionOf($a->id));
        $this->assertCount(30, $retraining->contributionOf($b->id));
    }

    public function test_analisis_hanya_dicatat_bukan_menyaring_data_latih(): void
    {
        Queue::fake();

        [$a, $b] = $this->duaAnalisisTerkoreksi();

        // Putaran "dipicu oleh" analisis A saja...
        $hasil = app(RetrainingService::class)->dispatch('sentiment', 3, null, $a->id);

        $this->assertNull($hasil['error']);
        Queue::assertPushed(RetrainModel::class);

        $training = ModelTraining::where('model_type', 'sentiment')->firstOrFail();

        // ...tetapi yang dilatih 60 baris, bukan 30 milik A.
        $this->assertSame(60, $training->total_samples);

        // Asalnya tetap tercatat: tanpa kolom ini riwayat tidak bisa menjawab
        // "lembaga mana yang baru ditambahkan pada iterasi ini", dan itu yang
        // dipakai untuk menamai iterasi di naskah.
        $this->assertSame($a->id, $training->text_analysis_id);
        $this->assertNotSame($b->id, $training->text_analysis_id);
    }

    public function test_kolam_gabungan_melewati_ambang_walau_tiap_lembaga_kecil(): void
    {
        // Konsekuensi yang dituju: #4 KPK (21) dan #5 Polri (22) sendiri-sendiri
        // di bawah ambang kelayakan 50, tetapi keduanya menambah kolam yang
        // sudah jauh di atasnya. Menilai per lembaga akan menolak putaran yang
        // sebenarnya sah.
        Queue::fake();

        $this->duaAnalisisTerkoreksi(30);

        $kecil = $this->analysis(21);
        $this->actingAs($kecil->user)->post(route('analysis.feedback.store', $kecil->id), [
            'corrections_json' => json_encode($this->koreksiUntuk($kecil)),
        ]);

        $hasil = app(RetrainingService::class)->dispatch('sentiment', 3, null, $kecil->id);

        $this->assertNull($hasil['error']);
        $this->assertSame(81, ModelTraining::where('model_type', 'sentiment')->firstOrFail()->total_samples);
    }

    // ------------------------------------- tombol kirim menutup putarannya

    /**
     * Menyalakan pelatihan ulang otomatis seperti `.env` studi kasus, dengan
     * ambang koreksi baru serendah mungkin - supaya yang benar-benar diuji di
     * bawah adalah penjaga kelayakan RetrainingService, bukan ambang ini.
     */
    private function nyalakanOtomatis(): void
    {
        config()->set('analysis.auto_retrain', [
            'enabled' => true,
            'min_new_corrections' => 1,
            'cooldown_hours' => 0,
            'model_type' => 'sentiment',
            'epochs' => 3,
        ]);
    }

    public function test_tombol_kirim_langsung_menjalankan_putaran_untuk_analisis_itu(): void
    {
        // Yang diminta pemilik proyek: menekan Kirim mengirim seluruh koreksi
        // satu analisis DAN langsung menjalankan putaran active learning untuk
        // analisis itu - tanpa membuka halaman admin.
        Queue::fake();
        $this->nyalakanOtomatis();

        $analysis = $this->analysis(60);

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode($this->koreksiUntuk($analysis)),
            ])
            ->assertRedirect()
            ->assertSessionHas('feedback_success', fn ($pesan) => str_contains($pesan, 'active learning'));

        Queue::assertPushed(RetrainModel::class);

        // Per analisis, bukan gabungan: itu inti pemisahan korpus per lembaga.
        foreach (ModelTraining::all() as $training) {
            $this->assertSame($analysis->id, $training->text_analysis_id);
        }
    }

    public function test_putaran_ditahan_penjaga_kelayakan_bukan_oleh_saklar(): void
    {
        // Putaran pertama studi kasus terpicu sendiri pada 19 koreksi dengan set
        // validasi 4 baris. Yang mencegahnya terulang kini penjaga di
        // RetrainingService - berlaku untuk SEMUA jalur - bukan lagi
        // ANALYSIS_AUTO_RETRAIN=false yang hanya mematikan satu jalur.
        Queue::fake();
        $this->nyalakanOtomatis();

        $analysis = $this->analysis(30);

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'corrections_json' => json_encode($this->koreksiUntuk($analysis)),
            ])
            // Ditolak, dan alasannya disebut - bukan diam. Menekan Kirim lalu
            // tidak terjadi apa-apa tanpa penjelasan persis kelas masalah yang
            // dikeluhkan titipan.
            ->assertSessionHas('feedback_success', fn ($pesan) => str_contains($pesan, '30')
                && str_contains($pesan, (string) RetrainingService::MIN_LAYAK));

        $this->assertSame(0, ModelTraining::count());
        Queue::assertNotPushed(RetrainModel::class);
    }
}
