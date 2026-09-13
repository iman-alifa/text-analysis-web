<?php

namespace Tests\Feature;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\User;
use App\Services\Evaluation\GoldenDatasetService;
use App\Support\PoolFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putaran yang dibatalkan, dan sidik jari isi kolam.
 *
 * Keduanya lahir dari kejadian yang sama. Iterasi 2 menghasilkan angka yang
 * NAIK (aspek 27,14 -> 31,57) tetapi tidak dapat direproduksi: titik
 * berangkatnya bukan bobot dasar melainkan checkpoint yang sudah tercemar dua
 * pelatihan verifikasi berkalimat sintetis, dan checkpoint antaranya sudah
 * tertimpa sendiri. Tidak ada seorang pun yang bisa menghitung ulang 31,57.
 *
 * Yang membuatnya berbahaya: kontaminasi itu tidak terlihat dari berkas bobot,
 * dari ukurannya, maupun dari `/health` - ketiganya tampak normal. Karena itu
 * dua hal dijaga di sini:
 *
 * 1. Baris yang dibatalkan TETAP ADA (jejak audit) tetapi angkanya tidak boleh
 *    ikut ke kurva, perbandingan, laporan, atau penomoran iterasi.
 * 2. Sidik jari isi kolam dicatat tiap putaran, supaya kolam yang tidak
 *    bertambah - atau berubah diam-diam - terbaca dari layar.
 */
class CancelledRoundTest extends TestCase
{
    use RefreshDatabase;

    private function dataset(): EvaluationDataset
    {
        $dataset = app(GoldenDatasetService::class)->store([
            ['text' => 'Pelayanannya memuaskan sekali di kantor itu', 'gold_sentiment' => 'positive'],
            ['text' => 'Sistemnya error terus setiap kali dibuka', 'gold_sentiment' => 'negative'],
        ], ['name' => 'Hold-out studi kasus', 'split' => 'test']);

        $dataset->update(['frozen_at' => now()]);

        return $dataset->fresh();
    }

    private function buatRun(EvaluationDataset $dataset, int $iterasi, bool $batal = false): EvaluationRun
    {
        return EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => $iterasi,
            'completed_at' => now()->addMinutes($iterasi),
            'metrics' => ['sentiment' => ['accuracy' => 80 + $iterasi, 'macro_f1' => 70 + $iterasi]],
            'model_info' => $batal
                ? ['batal' => true, 'alasan_batal' => 'aspek dari checkpoint tercemar']
                : ['sentiment_retrained' => true],
        ]);
    }

    // --------------------------------------------------------- run batal

    public function test_run_batal_tidak_ikut_scope_completed(): void
    {
        $dataset = $this->dataset();

        $this->buatRun($dataset, 0);
        $this->buatRun($dataset, 1);
        $this->buatRun($dataset, 2, batal: true);

        // Satu pintu untuk semua konsumen: kurva, perbandingan McNemar, laporan
        // markdown, dan penomoran iterasi semuanya lewat sini.
        $this->assertSame([0, 1], $dataset->runs()->completed()->orderBy('iteration')->pluck('iteration')->all());

        // Barisnya tetap ada - yang dibuang angkanya, bukan jejaknya.
        $this->assertSame(3, $dataset->runs()->count());
    }

    public function test_run_batal_tidak_menaikkan_nomor_iterasi(): void
    {
        // Konsekuensi yang memang diinginkan: iterasi 2 yang dibatalkan harus
        // diulang, jadi pengukuran berikutnya kembali menjadi iterasi 2 - bukan
        // melompat ke 3 dan meninggalkan lubang di kurva.
        $dataset = $this->dataset();

        $this->buatRun($dataset, 0);
        $this->buatRun($dataset, 1);
        $this->buatRun($dataset, 2, batal: true);

        $tertinggi = $dataset->runs()->completed()->max('iteration');

        $this->assertSame(1, $tertinggi);
    }

    public function test_penanda_batal_terbaca_dari_kedua_model(): void
    {
        $dataset = $this->dataset();
        $batal = $this->buatRun($dataset, 2, batal: true);
        $sah = $this->buatRun($dataset, 1);

        $this->assertTrue($batal->batal);
        $this->assertSame('aspek dari checkpoint tercemar', $batal->alasan_batal);
        $this->assertFalse($sah->batal);

        $training = ModelTraining::create([
            'model_type' => 'aspect',
            'status' => 'completed',
            'total_samples' => 60,
            'epochs' => 3,
            'learning_rate' => 0.00002,
            'completed_at' => now(),
            'result' => ['batal' => true, 'alasan_batal' => 'tidak berangkat dari bobot dasar'],
        ]);

        $this->assertTrue($training->batal);
        $this->assertSame('tidak berangkat dari bobot dasar', $training->alasan_batal);
    }

    public function test_halaman_evaluasi_tidak_mencetak_angka_run_batal(): void
    {
        $dataset = $this->dataset();
        $this->buatRun($dataset, 0);
        $this->buatRun($dataset, 2, batal: true);

        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.show', $dataset->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('BATAL', $html);
        $this->assertStringContainsString('tidak dapat direproduksi', $html);

        // 82/72 adalah angka run batal (iterasi 2). Angka yang terbaca akan
        // dikutip, jadi mencetaknya dengan penanda saja tidak cukup.
        $this->assertStringNotContainsString('>82%<', $html);
        $this->assertStringNotContainsString('>72%<', $html);
    }

    // ---------------------------------------------------- sidik jari kolam

    public function test_sidik_jari_sama_untuk_kolam_yang_sama_apa_pun_urutannya(): void
    {
        // Yang diidentifikasi adalah ISI kolam, bukan urutan pengambilannya dari
        // basis data - kalau tidak, sidik jarinya berubah tanpa kolamnya berubah.
        $this->assertSame(
            PoolFingerprint::of(['b', 'a', 'c']),
            PoolFingerprint::of(['a', 'b', 'c'])
        );

        $this->assertNotSame(
            PoolFingerprint::of(['a', 'b']),
            PoolFingerprint::of(['a', 'b', 'c'])
        );
    }

    public function test_sidik_jari_cocok_dengan_pool_fingerprint_layanan(): void
    {
        // Vektor acuan dihitung dari `pool_fingerprint()` di
        // ../nlp-api-service/app/utils/training.py. Harus cocok persis: gunanya
        // justru membandingkan angka Laravel dengan yang ditulis layanan ke
        // provenance.json, dan algoritma yang berbeda sedikit saja membuat
        // keduanya tidak pernah cocok - kelas kesalahan yang sama dengan
        // TextFingerprint pada penjaga kebocoran.
        $this->assertSame('13e228567e8249fc', PoolFingerprint::of(['b', 'a', 'c']));

        // Pemisah \x00 mencegah ["ab","c"] bertabrakan dengan ["a","bc"].
        $this->assertSame('629c2f14f654f026', PoolFingerprint::of(['ab', 'c']));
        $this->assertSame('aa795aa4bbb61179', PoolFingerprint::of(['a', 'bc']));

        // Non-ASCII lewat jalur yang sama.
        $this->assertSame('0e9a2dc629fa9386', PoolFingerprint::of([
            'Pelayanannya memuaskan sekali',
            'Sistemnya error terus',
            'emoji 🚀 dan aksen café',
        ]));

        $this->assertSame('e3b0c44298fc1c14', PoolFingerprint::of([]));
    }

    public function test_sidik_jari_dihitung_dari_kolom_text_muatan(): void
    {
        // Muatan sentimen `[{text,label}]` dan aspek `[{text,aspects}]` sama-sama
        // difingerprint atas kolom `text` saja, persis seperti layanan.
        $muatan = [
            ['text' => 'kalimat kedua', 'label' => 'positive'],
            ['text' => 'kalimat pertama', 'label' => 'negative'],
        ];

        $this->assertSame(
            PoolFingerprint::of(['kalimat pertama', 'kalimat kedua']),
            PoolFingerprint::ofPayload($muatan)
        );
    }
}
