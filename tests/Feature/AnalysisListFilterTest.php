<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kotak cari dan dua penyaring pada /analysis tidak melakukan apa pun - skrip
 * di halaman itu hanya memanggil console.log dan menyisakan komentar
 * "TODO: Implement AJAX filtering". Kini disaring di server.
 *
 * Harus di server, bukan di browser: daftarnya dipaginasi 15 baris, jadi
 * penyaringan sisi klien hanya akan menyentuh baris yang kebetulan tampil.
 */
class AnalysisListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->buat('Opini warga soal tarif', 'sentiment', 'completed', 'Data dari YouTube');
        $this->buat('Aspek pelayanan publik', 'aspect', 'processing');
        $this->buat('Topik pemberitaan', 'topic', 'failed');
    }

    private function buat(string $judul, string $jenis, string $status, ?string $keterangan = null): TextAnalysis
    {
        return TextAnalysis::create([
            'user_id' => $this->user->id,
            'title' => $judul,
            'description' => $keterangan,
            'input_type' => 'manual',
            'analysis_type' => $jenis,
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => $status,
        ]);
    }

    public function test_tanpa_penyaring_semua_tampil(): void
    {
        $this->actingAs($this->user)
            ->get(route('analysis.index'))
            ->assertOk()
            ->assertSee('Opini warga soal tarif')
            ->assertSee('Aspek pelayanan publik')
            ->assertSee('Topik pemberitaan');
    }

    public function test_pencarian_menyaring_judul(): void
    {
        $this->actingAs($this->user)
            ->get(route('analysis.index').'?q=tarif')
            ->assertOk()
            ->assertSee('Opini warga soal tarif')
            ->assertDontSee('Topik pemberitaan');
    }

    public function test_pencarian_juga_menyentuh_keterangan(): void
    {
        $this->actingAs($this->user)
            ->get(route('analysis.index').'?q=YouTube')
            ->assertOk()
            ->assertSee('Opini warga soal tarif')
            ->assertDontSee('Aspek pelayanan publik');
    }

    public function test_saring_menurut_jenis(): void
    {
        $this->actingAs($this->user)
            ->get(route('analysis.index').'?type=aspect')
            ->assertOk()
            ->assertSee('Aspek pelayanan publik')
            ->assertDontSee('Opini warga soal tarif');
    }

    public function test_saring_menurut_status(): void
    {
        $this->actingAs($this->user)
            ->get(route('analysis.index').'?status=failed')
            ->assertOk()
            ->assertSee('Topik pemberitaan')
            ->assertDontSee('Aspek pelayanan publik');
    }

    public function test_nilai_penyaring_karangan_diabaikan(): void
    {
        // Nilai di luar daftar tidak boleh masuk ke klausa where apa adanya.
        $this->actingAs($this->user)
            ->get(route('analysis.index').'?status=karangan&type=karangan')
            ->assertOk()
            ->assertSee('Opini warga soal tarif')
            ->assertSee('Topik pemberitaan');
    }

    public function test_penyaring_terbawa_ke_halaman_berikutnya(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->buat("Analisis sentimen nomor {$i}", 'sentiment', 'completed');
        }

        $html = $this->actingAs($this->user)
            ->get(route('analysis.index').'?type=sentiment')
            ->assertOk()
            ->getContent();

        // Tanpa withQueryString(), tautan halaman 2 akan kehilangan penyaringnya.
        $this->assertStringContainsString('type=sentiment', $html);
    }

    public function test_pilihan_penyaring_tetap_terpilih_setelah_dikirim(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('analysis.index').'?q=tarif&type=sentiment&status=completed')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="tarif"', $html);
        $this->assertStringContainsString('value="sentiment" selected', $html);
        $this->assertStringContainsString('value="completed" selected', $html);
        $this->assertStringContainsString('Bersihkan', $html);
    }

    public function test_analisis_milik_pengguna_lain_tidak_ikut_tersaring(): void
    {
        $lain = User::factory()->create();
        TextAnalysis::create([
            'user_id' => $lain->id,
            'title' => 'Milik orang lain soal tarif',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => 'completed',
        ]);

        $this->actingAs($this->user)
            ->get(route('analysis.index').'?q=tarif')
            ->assertOk()
            ->assertDontSee('Milik orang lain soal tarif');
    }
}
