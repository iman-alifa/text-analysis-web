<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\User;
use App\Support\ScraperExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Alur "ambil data YouTube lalu langsung analisis".
 *
 * Sebelumnya alurnya terputus di tengah: berkas hasil scraping hanya bisa
 * diunduh, lalu pengguna harus menebak sendiri bahwa berkas itu perlu diunggah
 * ulang lewat menu Analisis Baru. Lebih buruk lagi, tombol Unduh memakai
 * `deleteFileAfterSend()`, sehingga mengunduh berkasnya justru menghapusnya.
 *
 * Nama berkas datang dari URL dan dari formulir, jadi penjagaan path-nya diuji
 * di sini juga: satu lubang di situ berarti berkas mana pun di server bisa
 * dibaca sebagai "data analisis".
 */
class ScraperToAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private string $namaBerkas = 'youtube_comments_20260922010203.csv';

    protected function setUp(): void
    {
        parent::setUp();

        $dir = ScraperExport::directory();

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.$this->namaBerkas,
            "Comment,Likes\nPelayanannya memuaskan sekali,3\nSistemnya error terus setiap hari,1\n"
        );
    }

    protected function tearDown(): void
    {
        $path = ScraperExport::directory().DIRECTORY_SEPARATOR.$this->namaBerkas;

        if (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    // ------------------------------------------------ penjagaan nama berkas

    public function test_hanya_nama_berkas_ekspor_yang_sah_yang_diterima(): void
    {
        $this->assertNotNull(ScraperExport::path($this->namaBerkas));

        // Keluar dari direktori exports, dalam beberapa bentuk.
        $this->assertNull(ScraperExport::path('../../../.env'));
        $this->assertNull(ScraperExport::path('..\\..\\.env'));
        $this->assertNull(ScraperExport::path('/etc/passwd'));

        // Ekstensi di luar yang dihasilkan exportComments().
        $this->assertNull(ScraperExport::path('berkas.exe'));
        $this->assertNull(ScraperExport::path('berkas.php'));

        // Berkas yang memang tidak ada.
        $this->assertNull(ScraperExport::path('tidak-pernah-ada.csv'));
    }

    public function test_unduhan_tidak_menghapus_berkasnya(): void
    {
        // Kalau berkasnya terhapus saat diunduh, tombol "Analisis Sekarang"
        // beberapa detik kemudian menunjuk berkas yang sudah tidak ada.
        $this->actingAs(User::factory()->create())
            ->get(route('youtube.download', ['filename' => $this->namaBerkas]))
            ->assertOk();

        $this->assertNotNull(
            ScraperExport::path($this->namaBerkas),
            'Berkas ekspor hilang setelah diunduh.'
        );
    }

    // --------------------------------------------------- formulir analisis

    public function test_formulir_analisis_menampilkan_berkas_dari_scraper(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('analysis.create', ['dari_scraper' => $this->namaBerkas]))
            ->assertOk()
            ->assertSee($this->namaBerkas)
            ->assertSee('Berkas dari YouTube Scraper sudah siap');
    }

    public function test_nama_berkas_karangan_diabaikan_tanpa_menjatuhkan_halaman(): void
    {
        // Parameter URL bisa diisi apa saja. Yang tidak sah cukup diabaikan:
        // formulirnya tetap terbuka seperti biasa.
        $this->actingAs(User::factory()->create())
            ->get(route('analysis.create', ['dari_scraper' => '../../../.env']))
            ->assertOk()
            ->assertDontSee('Berkas dari YouTube Scraper sudah siap');
    }

    public function test_pratinjau_kolom_membaca_berkas_scraper(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('analysis.upload-file'), ['scraped_file' => $this->namaBerkas])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_pratinjau_menolak_berkas_di_luar_direktori_ekspor(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('analysis.upload-file'), ['scraped_file' => '../../../.env'])
            ->assertStatus(422);
    }

    public function test_analisis_bisa_dijalankan_dari_berkas_scraper(): void
    {
        // Antrean dipalsukan: yang diuji berkasnya terbaca dan analisis
        // tercipta, bukan pipeline NLP-nya.
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('analysis.store'), [
                'title' => 'Komentar YouTube lembaga X',
                'input_type' => 'file',
                'analysis_type' => 'sentiment',
                'scraped_file' => $this->namaBerkas,
                'file_has_header' => 'on',
                'text_column_name' => 'Comment',
            ])
            ->assertRedirect();

        $analysis = TextAnalysis::where('user_id', $user->id)->firstOrFail();

        // Berkas hasil scraping melewati pemrosesan yang sama dengan unggahan
        // manual, jadi kolom teksnya terbaca seperti biasa.
        $this->assertSame(2, $analysis->total_records);
        $this->assertContains('Pelayanannya memuaskan sekali', $analysis->raw_data);
    }

    public function test_store_menolak_berkas_scraper_yang_tidak_sah(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('analysis.store'), [
                'title' => 'Percobaan baca berkas lain',
                'input_type' => 'file',
                'analysis_type' => 'sentiment',
                'scraped_file' => '../../../.env',
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, TextAnalysis::count());
    }
}
