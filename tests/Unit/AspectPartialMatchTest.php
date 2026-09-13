<?php

namespace Tests\Unit;

use App\Services\Evaluation\ClassificationMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Pencocokan aspek dilaporkan dalam DUA mode sekaligus.
 *
 * Mode ketat menuntut nama aspek sama persis. Ia menghukum perbedaan
 * granularitas yang sebenarnya bukan kesalahan model: label emas
 * `dewan wakil rakyat` melawan prediksi `dewan` + `rakyat` dihitung satu false
 * negative DAN dua false positive, padahal konsepnya justru ditemukan.
 *
 * Terukur pada golden dataset 300 baris studi kasus: 73 dari 572 label emas
 * berupa frasa, dan micro-F1 naik 45,09 -> 51,34 begitu granularitas dimaafkan.
 *
 * Mode ketat TETAP dilaporkan sebagai batas bawah - pelonggaran ini tidak boleh
 * menyembunyikan apa pun.
 */
class AspectPartialMatchTest extends TestCase
{
    private ClassificationMetrics $metrik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrik = new ClassificationMetrics;
    }

    public function test_kedua_mode_selalu_dilaporkan(): void
    {
        $h = $this->metrik->aspects([
            ['gold' => ['pajak'], 'predicted' => ['pajak']],
        ]);

        $this->assertArrayHasKey('matching', $h);
        $this->assertArrayHasKey('partial', $h);
        $this->assertStringContainsString('ketat', $h['matching']);
        $this->assertStringContainsString('parsial', $h['partial']['matching']);
    }

    public function test_frasa_emas_dimaafkan_oleh_mode_parsial(): void
    {
        $h = $this->metrik->aspects([
            ['gold' => ['dewan wakil rakyat'], 'predicted' => ['dewan', 'rakyat']],
        ]);

        // Ketat: emas tidak ketemu, dua prediksi jadi false positive.
        $this->assertSame(0, $h['tp']);
        $this->assertSame(2, $h['fp']);
        $this->assertSame(1, $h['fn']);

        // Parsial: konsepnya memang ditemukan.
        $this->assertSame(1, $h['partial']['tp']);
        $this->assertSame(0, $h['partial']['fp']);
        $this->assertSame(0, $h['partial']['fn']);
    }

    public function test_aspek_yang_benar_salah_tetap_dihitung_salah(): void
    {
        /** Pelonggaran granularitas tidak boleh berubah jadi pengampunan. */
        $h = $this->metrik->aspects([
            ['gold' => ['korupsi'], 'predicted' => ['jagung']],
        ]);

        $this->assertSame(0, $h['partial']['tp']);
        $this->assertSame(1, $h['partial']['fp']);
        $this->assertSame(1, $h['partial']['fn']);
    }

    public function test_kata_sangat_pendek_tidak_membuat_aspek_asing_tampak_cocok(): void
    {
        /**
         * `ri` pada `dpr ri` tidak boleh membuatnya cocok dengan aspek lain
         * yang kebetulan memuat `ri`.
         */
        $h = $this->metrik->aspects([
            ['gold' => ['dpr ri'], 'predicted' => ['ri']],
        ]);

        $this->assertSame(0, $h['partial']['tp']);
    }

    public function test_mode_ketat_tidak_berubah_oleh_penambahan_mode_parsial(): void
    {
        $pasangan = [
            ['gold' => ['pajak', 'korupsi'], 'predicted' => ['pajak']],
            ['gold' => ['gaji'], 'predicted' => ['gaji', 'tunjangan']],
        ];

        $h = $this->metrik->aspects($pasangan);

        // 2 benar, 1 kelebihan, 1 kekurangan - persis seperti sebelum ada mode parsial.
        $this->assertSame(2, $h['tp']);
        $this->assertSame(1, $h['fp']);
        $this->assertSame(1, $h['fn']);
    }

    public function test_parsial_tidak_pernah_lebih_buruk_dari_ketat(): void
    {
        $pasangan = [
            ['gold' => ['sumber daya alam', 'presiden'], 'predicted' => ['presiden']],
            ['gold' => ['harga beras'], 'predicted' => ['beras']],
            ['gold' => ['polisi'], 'predicted' => ['jagung']],
        ];

        $h = $this->metrik->aspects($pasangan);

        $this->assertGreaterThanOrEqual($h['micro_f1'], $h['partial']['micro_f1']);
        $this->assertGreaterThanOrEqual($h['tp'], $h['partial']['tp']);
        $this->assertLessThanOrEqual($h['fn'], $h['partial']['fn']);
    }

    public function test_macro_bersyarat_support_mengabaikan_aspek_sekali_muncul(): void
    {
        /**
         * Macro biasa merata-ratakan setiap jenis aspek dengan bobot sama,
         * termasuk yang cuma muncul sekali dan skornya hanya bisa 0 atau 1.
         * Terukur pada golden dataset studi kasus: 204 jenis dinilai, hanya 63
         * yang muncul dua kali atau lebih.
         */
        $h = $this->metrik->aspects([
            ['gold' => ['pajak'], 'predicted' => ['pajak']],
            ['gold' => ['pajak'], 'predicted' => ['pajak']],
            // `sekalimuncul` hanya ada di satu baris dan tidak pernah ditemukan.
            ['gold' => ['sekalimuncul'], 'predicted' => []],
        ]);

        $this->assertArrayHasKey('macro_frequent', $h);
        $this->assertSame(2, $h['macro_frequent']['min_support']);
        $this->assertSame(1, $h['macro_frequent']['aspects'], 'hanya `pajak` yang lolos support');
        $this->assertSame(100.0, $h['macro_frequent']['macro_f1']);

        // Macro penuh tetap dilaporkan dan tetap terseret aspek langka.
        $this->assertLessThan($h['macro_frequent']['macro_f1'], $h['macro_f1']);
    }

    public function test_macro_bersyarat_aman_ketika_tidak_ada_yang_lolos(): void
    {
        $h = $this->metrik->aspects([
            ['gold' => ['a satu'], 'predicted' => ['b dua']],
        ]);

        $this->assertSame(0, $h['macro_frequent']['aspects']);
        $this->assertSame(0.0, $h['macro_frequent']['macro_f1']);
    }

    public function test_daftar_kosong_tetap_aman(): void
    {
        $h = $this->metrik->aspects([
            ['gold' => [], 'predicted' => []],
        ]);

        $this->assertTrue($h['available']);
        $this->assertSame(0, $h['partial']['tp']);
    }
}
