<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nama berkas pada rute unduh datang dari URL dan dulu disambung langsung ke
 * path. Karena responsnya memakai deleteFileAfterSend(), celah itu bukan hanya
 * membocorkan berkas di luar direktori exports tetapi juga menghapusnya.
 */
class YoutubeDownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $exportDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exportDir = storage_path('app/public/exports');

        if (! is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }
    }

    public function test_berkas_di_luar_direktori_export_tidak_bisa_diunduh(): void
    {
        $rahasia = storage_path('app/public/rahasia-uji.txt');
        file_put_contents($rahasia, 'isi rahasia');

        try {
            $this->actingAs(User::factory()->create())
                ->get('/youtube/download/'.urlencode('../rahasia-uji.txt'))
                ->assertNotFound();

            // Yang terpenting: berkasnya tidak ikut terhapus
            $this->assertFileExists($rahasia);
        } finally {
            @unlink($rahasia);
        }
    }

    public function test_ekstensi_di_luar_daftar_ditolak(): void
    {
        $env = $this->exportDir.'/uji.env';
        file_put_contents($env, 'APP_KEY=rahasia');

        try {
            $this->actingAs(User::factory()->create())
                ->get('/youtube/download/uji.env')
                ->assertNotFound();

            $this->assertFileExists($env);
        } finally {
            @unlink($env);
        }
    }

    public function test_berkas_ekspor_yang_sah_tetap_bisa_diunduh(): void
    {
        $sah = $this->exportDir.'/youtube_comments_20260903.csv';
        file_put_contents($sah, "teks\nhalo");

        $this->actingAs(User::factory()->create())
            ->get('/youtube/download/youtube_comments_20260903.csv')
            ->assertOk();

        // deleteFileAfterSend menghapusnya setelah terkirim
        @unlink($sah);
    }

    public function test_hanya_untuk_pengguna_yang_login(): void
    {
        $this->get('/youtube/download/youtube_comments_20260903.csv')
            ->assertRedirect(route('login'));
    }
}
