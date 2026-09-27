<?php

namespace Tests\Feature;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Lambang aplikasi dan gambar halaman depan.
 *
 * Gambar pahlawan halaman depan dulu foto stok dari Unsplash yang tampil kosong
 * begitu layanan itu tidak terjangkau. Tes ini menjaga supaya halaman depan
 * tidak kembali bergantung pada gambar dari luar.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_depan_memakai_lambang_dan_favicon_sendiri(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('favicon.svg', $html);
        $this->assertStringContainsString('Lambang AnalisisTeks', $html);
        $this->assertStringNotContainsString('unsplash.com', $html);
        $this->assertStringContainsString('Contoh tampilan halaman hasil analisis', $html);
    }

    public function test_dua_lambang_di_satu_halaman_tidak_berbagi_id_gradasi(): void
    {
        // Id yang sama dua kali membuat salah satu lambang kehilangan warna.
        $html = Blade::render('<x-logo-mark /><x-logo-mark />');

        preg_match_all('/id="(logo-grad-[^"]+)"/', $html, $cocok);

        $this->assertCount(2, $cocok[1]);
        $this->assertCount(2, array_unique($cocok[1]));
    }

    public function test_berkas_ikon_tersedia(): void
    {
        foreach (['favicon.svg', 'favicon.ico', 'apple-touch-icon.png'] as $berkas) {
            $this->assertFileExists(public_path($berkas));
            $this->assertGreaterThan(0, filesize(public_path($berkas)), $berkas);
        }
    }

    public function test_angka_halaman_depan_mengikuti_evaluasi_terakhir(): void
    {
        $dataset = EvaluationDataset::create(['name' => 'Hold-out', 'split' => 'test']);
        $buat = fn (int $iterasi, float $akurasi, float $f1, bool $batal = false) => EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => $iterasi,
            'completed_at' => now()->addMinutes($iterasi),
            'metrics' => [
                'sentiment' => ['n' => 300, 'accuracy' => $akurasi, 'accuracy_ci' => ['low' => 70.1, 'high' => 79.9]],
                'aspect' => ['available' => true, 'micro_f1' => $f1],
            ],
            'model_info' => $batal ? ['batal' => true] : [],
        ]);

        $buat(0, 71.0, 40.0);
        $buat(1, 76.25, 62.5);
        $buat(2, 99.9, 99.9, true); // run batal: angkanya tidak boleh tampil

        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('76,3%', $html);
        $this->assertStringContainsString('62,50%', $html);
        $this->assertStringContainsString('Naik dari 40,00%', $html);
        $this->assertStringNotContainsString('99,9', $html);
        $this->assertStringNotContainsString('studi kasus', strtolower($html));
    }

    public function test_tanpa_evaluasi_bagian_hasil_uji_tidak_tampil(): void
    {
        // Pemasangan baru: lebih baik tidak ada angka daripada angka lama.
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="hasil-uji"', $html);
        $this->assertStringNotContainsString('Akurasi</div>', $html);
        $this->assertStringNotContainsString('Akurasi sentimen</div>', $html);
    }

    public function test_halaman_depan_tidak_memuat_aset_berat(): void
    {
        // Halaman depan sempat terasa berat karena blob ber-blur yang
        // dianimasikan terus, AOS dari unpkg (stylesheet yang memblokir
        // render), dan app.js 92 KB yang tidak dipakai halaman ini.
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringNotContainsString('unpkg.com', $html);
        $this->assertStringNotContainsString('resources/js/app.js', $html);
        $this->assertStringNotContainsString('class="blob', $html);
        $this->assertStringNotContainsString('blur(70px)', $html);
    }

    public function test_f1_aspek_utama_adalah_pencocokan_parsial(): void
    {
        // Keputusan pemilik proyek (24 Sep 2026): angka utama ekstraksi aspek
        // memakai pencocokan parsial. Ketat hanya cadangan untuk run lama.
        $dataset = EvaluationDataset::create(['name' => 'Hold-out', 'split' => 'test']);

        EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => 0,
            'completed_at' => now(),
            'metrics' => [
                'sentiment' => ['n' => 300, 'accuracy' => 85.67],
                'aspect' => ['available' => true, 'micro_f1' => 74.38, 'partial' => ['micro_f1' => 80.06]],
            ],
        ]);

        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('80,06%', $html);
        $this->assertStringNotContainsString('74,38%', $html);
    }
}
