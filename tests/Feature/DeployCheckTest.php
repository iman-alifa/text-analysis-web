<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Perintah `deploy:check`.
 *
 * Perintah ini adalah gerbang terakhir sebelum aplikasi dinyatakan siap, dan
 * dipakai `deploy.sh` sebagai penentu berhasil atau tidaknya sebuah deploy.
 * Kalau ia melaporkan lulus untuk pemasangan yang sebenarnya rusak, ia justru
 * lebih berbahaya daripada tidak ada.
 *
 * Yang diuji di sini adalah **keputusannya**: kode keluar harus bukan nol
 * ketika ada pemeriksaan wajib yang gagal.
 */
class DeployCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keadaan "sehat" disiapkan eksplisit, bukan diwarisi dari lingkungan
        // tes. `phpunit.xml` menyetel QUEUE_CONNECTION=sync, dan itu memang
        // benar untuk menjalankan tes - tetapi merupakan salah satu keadaan
        // yang justru harus DITOLAK `deploy:check` pada pemasangan sungguhan.
        // Tanpa penyiapan ini, tes baseline gagal karena alasan yang tidak ada
        // hubungannya dengan apa yang sedang diuji.
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 4200);
        config()->set('queue.training_connection', 'training');
    }

    /**
     * Layanan NLP yang merespons normal.
     *
     * Dipanggil per tes, BUKAN di setUp(). `Http::fake()` menggabungkan stub
     * alih-alih menggantinya, dan stub yang cocok lebih dulu yang menang - jadi
     * stub "sehat" di setUp() akan membuat tes yang justru menguji layanan mati
     * tetap melihat balasan 200.
     */
    private function fakeNlpSehat(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'models' => []], 200),
            '*/api/normalize/aspects' => Http::response(['status' => 'success'], 200),
        ]);
    }

    public function test_lulus_pada_pemasangan_yang_sehat(): void
    {
        $this->fakeNlpSehat();

        $this->artisan('deploy:check')->assertSuccessful();
    }

    public function test_gagal_bila_retry_after_lebih_kecil_dari_timeout_job(): void
    {
        // Ini penyebab analisis berjalan ganda: job yang MASIH berjalan diambil
        // worker kedua karena antrean mengira ia hilang. Bawaan Laravel (90)
        // jauh lebih kecil dari durasi analisis nyata, jadi tanpa pemeriksaan
        // ini pemasangan baru hampir pasti kena.
        $this->fakeNlpSehat();

        config()->set('queue.connections.database.retry_after', 90);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('retry_after')
            ->assertFailed();
    }

    public function test_gagal_bila_timeout_http_analisis_menyamai_timeout_job(): void
    {
        // Contoh .env produksi lama berisi NLP_API_TIMEOUT=3600. Tes rantai
        // timeout tidak membaca berkas itu, jadi pemeriksaan di server-lah
        // satu-satunya yang bisa menangkapnya.
        $this->fakeNlpSehat();

        config()->set('services.nlp_api.timeout', 3600);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('Timeout HTTP analisis')
            ->assertFailed();
    }

    public function test_gagal_bila_rantai_timeout_pelatihan_terbalik(): void
    {
        // retry_after antrean training lebih kecil dari RetrainModel::$timeout:
        // pelatihan yang masih berjalan dilepas kembali ke antrean.
        $this->fakeNlpSehat();

        config()->set('queue.connections.training.retry_after', 4200);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('Rantai pelatihan')
            ->assertFailed();
    }

    public function test_gagal_bila_antrean_memakai_sync(): void
    {
        // Dengan sync, analisis berjalan di dalam permintaan web dan pasti
        // kehabisan waktu pada korpus yang sebenarnya.
        $this->fakeNlpSehat();

        config()->set('queue.default', 'sync');

        $this->artisan('deploy:check')->assertFailed();
    }

    public function test_gagal_bila_app_key_kosong(): void
    {
        $this->fakeNlpSehat();

        config()->set('app.key', '');

        $this->artisan('deploy:check')->assertFailed();
    }

    public function test_mode_produksi_menolak_app_debug_menyala(): void
    {
        // Di lingkungan lokal APP_DEBUG=true wajar, jadi pemeriksaannya hanya
        // berlaku ketika `--produksi` diminta. Tanpa pembedaan itu, perintahnya
        // akan selalu merah di mesin pengembang dan berhenti dipercaya.
        $this->fakeNlpSehat();

        config()->set('app.debug', true);

        $this->artisan('deploy:check')->assertSuccessful();

        $this->artisan('deploy:check', ['--produksi' => true])
            ->expectsOutputToContain('APP_DEBUG')
            ->assertFailed();
    }

    public function test_repo_bersarang_di_public_hanya_menggagalkan_mode_produksi(): void
    {
        // `public/anotasi/` adalah klon repositori tersendiri, jadi `.git` di
        // dalamnya memang ada di mesin pengembang. Itu wajar selagi tidak
        // terbuka ke publik, dan hanya berbahaya bila ikut ter-deploy.
        if (! is_dir(public_path('anotasi/.git'))) {
            $this->markTestSkipped('Tidak ada repositori bersarang di public/ pada salinan ini.');
        }

        $this->fakeNlpSehat();

        $this->artisan('deploy:check')->assertSuccessful();

        $this->artisan('deploy:check', ['--produksi' => true])
            ->expectsOutputToContain('.git')
            ->assertFailed();
    }

    public function test_kunci_api_yang_ditolak_menggagalkan_walau_health_hijau(): void
    {
        // Celah yang ditutup: `/health` sengaja terbuka tanpa kunci, jadi dulu
        // perintah ini hijau walaupun setiap analisis ditolak 401. Diverifikasi
        // juga terhadap layanan sungguhan dengan kunci yang sengaja disalahkan.
        Http::fake([
            '*/health' => Http::response(['status' => 'healthy', 'models' => []], 200),
            '*/api/normalize/aspects' => Http::response(['detail' => 'Kunci API tidak valid.'], 401),
        ]);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('Kunci API diterima layanan (HTTP 401)')
            ->assertFailed();
    }

    public function test_melaporkan_layanan_nlp_yang_tidak_merespons(): void
    {
        Http::fake(['*/health' => Http::response('', 500)]);

        $this->artisan('deploy:check')
            ->expectsOutputToContain('Layanan NLP')
            ->assertFailed();
    }
}
