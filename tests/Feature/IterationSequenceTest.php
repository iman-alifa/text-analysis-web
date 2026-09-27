<?php

namespace Tests\Feature;

use App\Models\EvaluationDataset;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\HoldoutEvaluationService;
use App\Services\RetrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Rangkaian enam iterasi active learning, satu per analisis.
 *
 * Dua hal yang harus dijaga sepanjang enam putaran, dan keduanya berasal dari
 * kesalahan nyata pada putaran pertama:
 *
 * 1. **Nomor iterasi hanya maju bila modelnya berubah.** Dua pengukuran
 *    baseline berturut-turut - keduanya sebelum pelatihan apa pun, angkanya
 *    identik sampai desimal terakhir - sempat tercatat sebagai iterasi 0 dan
 *    iterasi 1, sehingga kurvanya menampilkan "iterasi tanpa perbaikan" yang
 *    tidak pernah terjadi.
 * 2. **Bobot tidak boleh ditimpa sebelum iterasinya diukur.** Enam putaran
 *    berarti enam kesempatan melakukan kesalahan yang tidak bisa dibatalkan:
 *    begitu checkpoint baru diterima, bobot lama hilang dan iterasi itu tidak
 *    bisa diukur belakangan.
 */
class IterationSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function dataset(): EvaluationDataset
    {
        $golden = app(GoldenDatasetService::class);

        $dataset = $golden->store([
            ['text' => 'Pelayanannya memuaskan sekali di kantor itu', 'gold_sentiment' => 'positive'],
            ['text' => 'Sistemnya error terus setiap kali dibuka', 'gold_sentiment' => 'negative'],
        ], ['name' => 'Hold-out studi kasus', 'split' => 'test']);

        $dataset->update(['frozen_at' => now()]);

        return $dataset->fresh();
    }

    private function fakeNlp(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'models' => [
                'sentiment_source' => 'crypter70/IndoBERT-Sentiment-Analysis',
                'sentiment_retrained' => false,
            ]], 200),
            '*/api/analyze/sentiment' => Http::response(['status' => 'success', 'results' => [
                'predictions' => [
                    ['sentiment' => 'positive', 'confidence' => 0.96],
                    ['sentiment' => 'negative', 'confidence' => 0.94],
                ],
            ]], 200),
            '*/api/analyze/aspect' => Http::response(['status' => 'success', 'results' => []], 200),
        ]);
    }

    /**
     * `null` berarti pelatihan gabungan - dipakai bila identitas analisisnya
     * tidak relevan bagi yang sedang diuji. Kolomnya berkendala foreign key,
     * jadi id karangan akan ditolak basis data.
     */
    private function pelatihanSelesai(?int $analysisId = null, string $modelType = 'sentiment'): ModelTraining
    {
        // Penjaga "belum diukur" hanya berlaku bila ADA test set beku untuk
        // mengukurnya. Skenario di tes ini selalu mengandaikan ada.
        if (! EvaluationDataset::ujiAktif()) {
            $this->dataset();
        }

        return ModelTraining::create([
            'model_type' => $modelType,
            'text_analysis_id' => $analysisId,
            'status' => 'completed',
            'total_samples' => 60,
            'epochs' => 3,
            'learning_rate' => 0.00002,
            'completed_at' => now(),
        ]);
    }

    public function test_mengukur_ulang_model_yang_sama_bukan_iterasi_baru(): void
    {
        $dataset = $this->dataset();
        $this->fakeNlp();

        $evaluasi = app(HoldoutEvaluationService::class);

        $pertama = $evaluasi->run($dataset, ['label' => 'Iterasi 0']);
        $kedua = $evaluasi->run($dataset, ['label' => 'Iterasi 0 - ulang']);

        // Tidak ada pelatihan di antaranya, jadi modelnya belum berubah.
        $this->assertSame(0, $pertama->iteration);
        $this->assertSame(0, $kedua->iteration);
    }

    public function test_iterasi_maju_hanya_setelah_pelatihan_selesai(): void
    {
        $dataset = $this->dataset();
        $this->fakeNlp();

        $evaluasi = app(HoldoutEvaluationService::class);

        $this->assertSame(0, $evaluasi->run($dataset, ['label' => 'Iterasi 0'])->iteration);

        $this->travel(1)->minutes();
        $pelatihan = $this->pelatihanSelesai();

        $iterasi1 = $evaluasi->run($dataset, ['label' => 'Iterasi 1 - TNI']);

        $this->assertSame(1, $iterasi1->iteration);

        // Dan tertaut otomatis ke pelatihan yang menghasilkan bobotnya, supaya
        // rantai "pelatihan -> pengukuran" terbaca tanpa mengingat urutannya.
        $this->assertSame($pelatihan->id, $iterasi1->model_training_id);
    }

    public function test_enam_putaran_per_analisis_bernomor_berurutan(): void
    {
        $dataset = $this->dataset();
        $this->fakeNlp();

        $evaluasi = app(HoldoutEvaluationService::class);
        $evaluasi->run($dataset, ['label' => 'Iterasi 0']);

        // Satu putaran per lembaga, enam lembaga.
        foreach (range(1, 6) as $putaran) {
            $this->travel(1)->minutes();
            $this->pelatihanSelesai();

            $run = $evaluasi->run($dataset, ['label' => "Iterasi {$putaran}"]);

            $this->assertSame($putaran, $run->iteration, "putaran {$putaran}");
        }

        $this->assertSame(
            [0, 1, 2, 3, 4, 5, 6],
            $dataset->runs()->completed()->orderBy('iteration')->pluck('iteration')->all()
        );
    }

    // ------------------------------------------------ penjaga urutan

    private function analisisBerkoreksi(int $jumlah = 60): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Tentara Nasional Indonesia',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => range(1, $jumlah),
            'total_records' => $jumlah,
            'status' => 'completed',
        ]);

        foreach (range(1, $jumlah) as $i) {
            TrainingItem::create([
                'text_analysis_id' => $analysis->id,
                'text_content' => "komentar warga nomor {$i} tentang pelayanan lembaga",
                'predicted_sentiment' => 'neutral',
                'predicted_method' => 'indobert',
                'confidence_score' => 0.5,
                'corrected_sentiment' => 'positive',
                // Aspek ikut dikoreksi supaya muatan aspek juga melewati ambang
                // kelayakan. Tanpa ini, permintaan `--model=aspect` ditolak lebih
                // dulu oleh penjaga ambang dan penjaga "belum diukur" - yang
                // justru sedang diuji - tidak pernah tercapai.
                'corrected_aspects' => ['pelayanan'],
                'is_corrected' => true,
                'verified_at' => now(),
            ]);
        }

        return $analysis;
    }

    public function test_menolak_melatih_bila_iterasi_sebelumnya_belum_diukur(): void
    {
        // Penjaga terpenting rangkaian enam iterasi. Tanpa ini, melatih lembaga
        // berikutnya menimpa bobot yang iterasinya belum sempat diukur - dan
        // angka itu hilang selamanya.
        $analysis = $this->analisisBerkoreksi();
        $this->pelatihanSelesai($analysis->id);

        $this->artisan('retrain:analysis', ['analysis' => $analysis->id])
            ->expectsOutputToContain('Pelatihan sebelumnya belum diukur')
            ->assertFailed();
    }

    public function test_boleh_melatih_setelah_iterasinya_diukur(): void
    {
        // Antrean dipalsukan: yang diuji izin menjalankannya, bukan pelatihannya.
        // Tanpa ini job berjalan sinkron dan benar-benar menembak /api/retrain.
        Queue::fake();

        $analysis = $this->analisisBerkoreksi();
        $dataset = $this->dataset();
        $this->fakeNlp();

        $this->pelatihanSelesai($analysis->id);

        $this->travel(1)->minutes();
        app(HoldoutEvaluationService::class)->run($dataset, ['label' => 'Iterasi 1']);

        $this->artisan('retrain:analysis', ['analysis' => $analysis->id])
            ->assertSuccessful();
    }

    // ------------------------------------------ cakupan penjaga per modul

    /**
     * Penjaga disaring per modul karena kedua modul menulis ke berkas berbeda:
     * sentimen ke `models/sentiment_retrained/`, aspek ke
     * `models/aspect_retrained.pt`. Melatih yang satu tidak bisa menimpa yang
     * lain.
     *
     * Cakupan yang terlalu lebar bukan sekadar merepotkan. Keadaan yang
     * memunculkannya nyata: pada iterasi 2 pelatihan sentimen gagal (os error
     * 1224) sementara aspek selesai, dan menuruti penjaga yang tidak disaring
     * berarti mengukur gabungan "aspek iterasi 2 + sentimen bobot dasar" -
     * titik campuran yang tidak sesuai iterasi mana pun. Lebih buruk lagi,
     * begitu "paksa" jadi kebiasaan setiap kali satu modul gagal, penjaga yang
     * BENAR ikut kehilangan daya.
     */
    private function penjaga(string $modelType): ?ModelTraining
    {
        return app(RetrainingService::class)->unmeasuredTraining($modelType);
    }

    public function test_aspek_belum_diukur_tidak_menghalangi_pelatihan_sentimen(): void
    {
        $analysis = $this->analisisBerkoreksi();
        $this->pelatihanSelesai($analysis->id, 'aspect');

        // Modul yang belum diukur adalah aspek; bobot sentimen tidak tersentuh.
        $this->assertNull($this->penjaga('sentiment'));

        // Tetapi melatih aspek - atau keduanya - memang menimpanya.
        $this->assertNotNull($this->penjaga('aspect'));
        $this->assertNotNull($this->penjaga('both'));
    }

    public function test_sentimen_belum_diukur_tidak_menghalangi_pelatihan_aspek(): void
    {
        $analysis = $this->analisisBerkoreksi();
        $this->pelatihanSelesai($analysis->id, 'sentiment');

        $this->assertNull($this->penjaga('aspect'));
        $this->assertNotNull($this->penjaga('sentiment'));
        $this->assertNotNull($this->penjaga('both'));
    }

    public function test_pelatihan_both_yang_belum_diukur_memblokir_kedua_modul(): void
    {
        // Bagian yang paling mudah salah kalau penyaringnya cuma
        // `where('model_type', $modelType)`: pelatihan `both` menimpa KEDUA
        // berkas, jadi ia harus tetap memblokir permintaan satu modul.
        $analysis = $this->analisisBerkoreksi();
        $this->pelatihanSelesai($analysis->id, 'both');

        $this->assertNotNull($this->penjaga('sentiment'));
        $this->assertNotNull($this->penjaga('aspect'));
        $this->assertNotNull($this->penjaga('both'));
    }

    public function test_perintah_boleh_mengulang_sentimen_saat_yang_tertunda_aspek(): void
    {
        // Jalur ujung ke ujung dari keadaan iterasi 2 yang sebenarnya: pelatihan
        // sentimen gagal, aspek selesai dan belum diukur.
        Queue::fake();

        $analysis = $this->analisisBerkoreksi();
        $this->pelatihanSelesai($analysis->id, 'aspect');

        $this->artisan('retrain:analysis', ['analysis' => $analysis->id, '--model' => 'sentiment'])
            ->assertSuccessful();
    }

    public function test_perintah_tetap_menolak_mengulang_modul_yang_belum_diukur(): void
    {
        // Tes terpisah, bukan lanjutan dari yang di atas: dispatch pertama
        // meninggalkan pelatihan berstatus pending, sehingga permintaan kedua
        // akan ditolak "masih ada pelatihan yang berjalan" - penolakan yang
        // benar, tetapi bukan yang sedang diuji di sini.
        Queue::fake();

        $analysis = $this->analisisBerkoreksi();
        $this->pelatihanSelesai($analysis->id, 'aspect');

        $this->artisan('retrain:analysis', ['analysis' => $analysis->id, '--model' => 'aspect'])
            ->expectsOutputToContain('Pelatihan sebelumnya belum diukur')
            ->assertFailed();
    }
}
