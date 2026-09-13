<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Evaluation\ClassificationMetrics;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\HoldoutEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Keempat angka aspek harus dilaporkan, bukan hanya mode ketat.
 *
 * Empat angka disimpan tetapi hanya dua yang pernah tampil. Yang tersembunyi
 * justru yang menjawab dua pertanyaan yang pasti ditanyakan penguji:
 *
 * 1. **Parsial** - seberapa besar hukuman granularitas frasa. Label emas
 *    `dewan wakil rakyat` versus prediksi `dewan` + `rakyat` dihitung satu
 *    false negative DAN dua false positive pada mode ketat, padahal konsepnya
 *    justru ditemukan.
 * 2. **Macro bersyarat support** - seberapa besar macro ditarik turun aspek
 *    yang cuma muncul sekali. Terukur pada golden dataset studi kasus: macro
 *    melonjak 15,87 -> 29,12 begitu aspek sekali-muncul dikeluarkan, yang
 *    berarti angka rendah tadi memang didominasi aspek tanpa makna statistik.
 *
 * Yang ketat tetap dilaporkan sebagai batas bawah - pelonggaran apa pun tidak
 * boleh menyembunyikannya.
 */
class AspectMetricReportingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pasangan yang sengaja memperlihatkan keempat angkanya berbeda:
     * satu label emas berupa frasa, dan banyak aspek yang cuma muncul sekali.
     *
     * @return array<int, array{gold: array, predicted: array}>
     */
    private function pasangan(): array
    {
        return [
            ['gold' => ['dewan wakil rakyat'], 'predicted' => ['dewan', 'rakyat']],
            ['gold' => ['pelayanan'], 'predicted' => ['pelayanan']],
            ['gold' => ['pelayanan'], 'predicted' => ['pelayanan']],
            ['gold' => ['anggaran sekali'], 'predicted' => ['xyz']],
            ['gold' => ['unik satu'], 'predicted' => ['abc']],
            ['gold' => ['unik dua'], 'predicted' => ['def']],
        ];
    }

    public function test_keempat_angka_dihitung_dan_saling_berbeda(): void
    {
        $hasil = app(ClassificationMetrics::class)->aspects($this->pasangan());

        // 1 & 2: ketat.
        $this->assertEqualsWithDelta(30.77, $hasil['micro_f1'], 0.01);
        $this->assertEqualsWithDelta(10.0, $hasil['macro_f1'], 0.01);

        // 3: parsial - granularitas frasa dimaafkan, jadi lebih tinggi.
        $this->assertEqualsWithDelta(50.0, $hasil['partial']['micro_f1'], 0.01);
        $this->assertGreaterThan($hasil['micro_f1'], $hasil['partial']['micro_f1']);

        // 4: macro atas aspek yang cukup sering muncul.
        $this->assertSame(2, $hasil['macro_frequent']['min_support']);
        $this->assertSame(1, $hasil['macro_frequent']['aspects']);
        $this->assertGreaterThan($hasil['macro_f1'], $hasil['macro_frequent']['macro_f1']);

        // Sembilan dari sepuluh jenis aspek cuma muncul sekali - itulah yang
        // menarik macro penuh ke bawah.
        $this->assertCount(10, $hasil['per_aspect']);
    }

    public function test_angka_ketat_tetap_ada_sebagai_batas_bawah(): void
    {
        // Pelonggaran apa pun tidak boleh menggantikan yang ketat.
        $hasil = app(ClassificationMetrics::class)->aspects($this->pasangan());

        $this->assertArrayHasKey('micro_f1', $hasil);
        $this->assertArrayHasKey('macro_f1', $hasil);
        $this->assertStringContainsString('ketat', $hasil['matching']);
        $this->assertStringContainsString('parsial', $hasil['partial']['matching']);
    }

    public function test_halaman_evaluasi_menampilkan_parsial_dan_macro_bersyarat(): void
    {
        $dataset = $this->datasetBeraspek();

        $this->fakeNlp();

        app(HoldoutEvaluationService::class)->run($dataset);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.show', $dataset->id))
            ->assertOk()
            // Ketiga baris tabel pencocokan harus ada, bukan hanya yang ketat.
            ->assertSee('Ketat')
            ->assertSee('Parsial')
            ->assertSee('aspek support', false)
            // Dan penjelasan mengapa macro penuh rendah.
            ->assertSee('muncul sekali saja', false);
    }

    private function datasetBeraspek(): \App\Models\EvaluationDataset
    {
        $golden = app(GoldenDatasetService::class);

        $dataset = $golden->store([
            [
                'text' => 'Dewan wakil rakyat harus lebih terbuka soal anggaran',
                'gold_sentiment' => 'negative',
                'gold_aspects' => ['dewan wakil rakyat'],
            ],
            [
                'text' => 'Pelayanannya cepat sekali di kantor itu',
                'gold_sentiment' => 'positive',
                'gold_aspects' => ['pelayanan'],
            ],
        ], ['name' => 'Golden aspek', 'split' => 'test']);

        // Bentuk dasar diisi langsung: yang diuji di sini pelaporannya, bukan
        // jalur normalisasinya (sudah ada tesnya sendiri).
        foreach ($dataset->items()->orderBy('id')->get() as $i => $item) {
            $item->update([
                'gold_aspects_normalized' => $i === 0 ? ['dewan wakil rakyat'] : ['layan'],
            ]);
        }

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
            '*/api/analyze/sentiment' => Http::response([
                'status' => 'success',
                'results' => ['predictions' => [
                    ['sentiment' => 'negative', 'confidence' => 0.93],
                    ['sentiment' => 'positive', 'confidence' => 0.95],
                ]],
            ], 200),
            '*/api/analyze/aspect' => Http::response([
                'status' => 'success',
                'results' => [
                    'document_aspects' => [['dewan', 'rakyat'], ['pelayanannya']],
                    'document_aspects_normalized' => [['dewan', 'rakyat'], ['layan']],
                ],
            ], 200),
        ]);
    }
}
