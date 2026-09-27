<?php

namespace App\Console\Commands;

use App\Services\NLPApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Memeriksa kesiapan pemasangan, dari sisi yang benar-benar bisa diperiksa
 * mesin.
 *
 * Dibuat karena tiga kesalahan pemasangan yang paling sering terjadi semuanya
 * **tidak menjatuhkan aplikasi**: halamannya tetap membalas 200, dan barulah
 * ketahuan setelah seseorang mencoba memakainya.
 *
 * - Worker antrean tidak berjalan: analisis berhenti selamanya di "diproses".
 * - `public/hot` tertinggal: setiap halaman tampil tanpa gaya sama sekali.
 * - Folder di `public/` menelan rute: rutenya 404 padahal `route:list` normal.
 *
 * Kode keluarnya bukan nol bila ada pemeriksaan yang GAGAL, sehingga perintah
 * ini bisa dipakai sebagai gerbang di skrip deploy. Peringatan tidak
 * menggagalkan: sebagian memang keadaan yang sah pada pemasangan tertentu.
 */
class DeployCheck extends Command
{
    protected $signature = 'deploy:check {--produksi : Perketat pemeriksaan yang hanya berlaku di server produksi}';

    protected $description = 'Periksa kesiapan pemasangan sebelum atau sesudah deploy';

    private int $gagal = 0;

    private int $peringatan = 0;

    public function handle(NLPApiService $nlp): int
    {
        $produksi = $this->option('produksi') || app()->environment('production');

        $this->newLine();
        $this->line('  <options=bold>Pemeriksaan kesiapan pemasangan</>');
        $this->line('  Lingkungan: <options=bold>'.app()->environment().'</>');
        $this->newLine();

        $this->bagian('Konfigurasi dasar');
        $this->periksaKonfigurasi($produksi);

        $this->bagian('Berkas dan folder');
        $this->periksaBerkas($produksi);

        $this->bagian('Basis data');
        $this->periksaBasisData();

        $this->bagian('Antrean');
        $this->periksaAntrean();

        $this->bagian('Layanan NLP');
        $this->periksaLayananNlp($nlp);

        $this->bagian('AI (Gemini)');
        $this->periksaGemini();

        return $this->ringkasan();
    }

    // ------------------------------------------------------------ pemeriksaan

    private function periksaKonfigurasi(bool $produksi): void
    {
        $this->nilai(
            'APP_KEY terisi',
            filled(config('app.key')),
            'Jalankan: php artisan key:generate'
        );

        $this->nilai(
            'APP_URL bukan localhost',
            ! $produksi || ! str_contains((string) config('app.url'), 'localhost'),
            'APP_URL dipakai menyusun tautan email dan aset. Isi dengan domain sebenarnya.',
            wajib: $produksi
        );

        if ($produksi) {
            $this->nilai(
                'APP_DEBUG mati',
                config('app.debug') === false,
                'APP_DEBUG=true menampilkan jejak kode dan isi konfigurasi kepada pengunjung.'
            );

            $this->nilai(
                'APP_ENV = production',
                app()->environment('production'),
                'Setel APP_ENV=production di .env.'
            );

            $this->nilai(
                'HTTPS pada APP_URL',
                str_starts_with((string) config('app.url'), 'https://'),
                'Pasang sertifikat lalu ubah APP_URL menjadi https.',
                wajib: false
            );
        }

        // retry_after HARUS lebih besar dari timeout job. Bila lebih kecil, job
        // yang masih berjalan diambil worker kedua dan analisis yang sama
        // berjalan berkali-kali sekaligus.
        $retryAfter = (int) config('queue.connections.database.retry_after', 90);

        $this->nilai(
            "retry_after ({$retryAfter}) > timeout job (3600)",
            $retryAfter > 3600,
            'Setel DB_QUEUE_RETRY_AFTER=4200. Nilai di bawah 3600 membuat analisis panjang berjalan ganda.'
        );

        // HTTP analisis harus < timeout job, supaya yang menghentikan pekerjaan
        // macet adalah job yang bisa mencatat sebabnya. Contoh .env produksi
        // lama berisi NLP_API_TIMEOUT=3600 - sama dengan batas job - dan
        // tidak ada yang menangkapnya karena tes tidak membaca berkas itu.
        $httpAnalisis = (int) config('services.nlp_api.timeout', 0);

        $this->nilai(
            "Timeout HTTP analisis ({$httpAnalisis}) < timeout job (3600)",
            $httpAnalisis > 0 && $httpAnalisis < 3600,
            'Setel NLP_API_TIMEOUT=600.'
        );

        $httpTopik = (int) config('services.nlp_api.topic_timeout', 0);

        $this->nilai(
            "Timeout HTTP topik ({$httpTopik}) < timeout job (3600)",
            $httpTopik > 0 && $httpTopik < 3600,
            'Setel NLP_API_TOPIC_TIMEOUT=2400. Pemodelan topik 3.366 komentar terukur 972 detik di VPS produksi.'
        );

        // Rantai pelatihan ulang: HTTP retrain < RetrainModel::$timeout <
        // retry_after antrean 'training'. HTTP yang menyerah lebih dulu tidak
        // menghentikan pelatihan di layanan - checkpoint tetap tersimpan
        // sementara riwayat mencatat 'failed'.
        $httpLatih = (int) config('services.nlp_api.retrain_timeout', 0);
        $jobLatih = (new \ReflectionClass(\App\Jobs\RetrainModel::class))->getDefaultProperties()['timeout'] ?? 0;
        $retryLatih = (int) config('queue.connections.training.retry_after', 0);

        $this->nilai(
            "Rantai pelatihan: HTTP ({$httpLatih}) < job ({$jobLatih}) < retry_after ({$retryLatih})",
            $httpLatih > 0 && $httpLatih < $jobLatih && $jobLatih < $retryLatih,
            'Setel NLP_API_RETRAIN_TIMEOUT=6600 dan DB_TRAINING_RETRY_AFTER=7500.'
        );

        // Rantai evaluasi hold-out: HTTP analisis < RunHoldoutEvaluation::$timeout
        // < retry_after antrean bawaan. Job harus bertahan lebih lama dari satu
        // permintaan HTTP, supaya batch yang kehabisan waktu tercatat dengan
        // pesannya sendiri, bukan sebagai job yang dibunuh tanpa keterangan.
        $jobEval = (new \ReflectionClass(\App\Jobs\RunHoldoutEvaluation::class))->getDefaultProperties()['timeout'] ?? 0;

        $this->nilai(
            "Rantai evaluasi: HTTP ({$httpAnalisis}) < job ({$jobEval}) < retry_after ({$retryAfter})",
            $httpAnalisis > 0 && $httpAnalisis < $jobEval && $jobEval < $retryAfter,
            'Evaluasi yang dihentikan di tengah jalan ditandai gagal; hanya pesannya yang kurang jelas.',
            wajib: false
        );

        $this->nilai(
            "Pelatihan memakai antrean sendiri ('".config('queue.training_connection')."')",
            config('queue.training_connection') !== 'sync',
            'Setel QUEUE_TRAINING_CONNECTION=training dan jalankan worker kedua (lihat DEPLOYMENT.md).'
        );

        $this->nilai(
            'NLP_API_URL terisi',
            filled(config('services.nlp_api.url')),
            'Tanpa ini seluruh analisis gagal.'
        );
    }

    private function periksaBerkas(bool $produksi): void
    {
        // public/hot membuat @vite menunjuk server pengembangan Vite. Bila
        // server itu tidak berjalan, SELURUH halaman kehilangan gayanya.
        $hot = public_path('hot');

        if (is_file($hot)) {
            if ($produksi) {
                $this->nilai('public/hot tidak ada', false, 'Hapus: rm -f public/hot, lalu npm run build');
            } else {
                $this->catatan('public/hot ada (wajar bila `npm run dev` sedang berjalan)');
            }
        } else {
            $this->nilai('public/hot tidak ada', true, '');
        }

        $manifest = public_path('build/manifest.json');

        $this->nilai(
            'Aset terbangun (public/build)',
            is_file($manifest),
            'Jalankan: npm run build',
            wajib: $produksi || ! is_file($hot)
        );

        // Diperiksa lewat realpath(), bukan is_link()/is_dir(). Symlink yang
        // dibuat di Windows membuat keduanya mengembalikan false walaupun
        // tautannya berfungsi, dan pemeriksaan yang salah di sini akan
        // menyuruh orang menjalankan storage:link yang sebenarnya tidak perlu.
        $tujuan = @realpath(public_path('storage'));
        $seharusnya = @realpath(storage_path('app/public'));

        $this->nilai(
            'Symlink storage menunjuk storage/app/public',
            $tujuan !== false && $seharusnya !== false && $tujuan === $seharusnya,
            'Jalankan: php artisan storage:link'
        );

        foreach (['storage', 'bootstrap/cache'] as $folder) {
            $this->nilai(
                "{$folder} bisa ditulis",
                is_writable(base_path($folder)),
                "chmod -R 775 {$folder}"
            );
        }

        // Folder di public/ yang namanya sama dengan rute akan MENELAN rutenya:
        // web server melayani foldernya dan permintaan tidak pernah sampai ke
        // Laravel. `route:list` tetap normal, jadi penyebabnya tidak terlihat.
        $this->periksaTabrakanRute();

        $this->periksaRepoDiPublic($produksi);
    }

    private function periksaTabrakanRute(): void
    {
        // storage dikecualikan: public/storage adalah symlink storage:link, dan
        // menaungi rute storage/{path} memang yang diinginkan.
        $folder = array_diff(
            array_map('basename', array_filter(glob(public_path('*')) ?: [], 'is_dir')),
            ['storage']
        );

        $tabrakan = [];

        foreach (Route::getRoutes() as $rute) {
            $segmen = explode('/', $rute->uri())[0];

            if (in_array($segmen, $folder, true)) {
                $tabrakan[$segmen] = $rute->uri();
            }
        }

        $this->nilai(
            'Tidak ada folder public/ yang menelan rute',
            empty($tabrakan),
            empty($tabrakan)
                ? ''
                : 'Bentrok: '.collect($tabrakan)->map(fn ($uri, $dir) => "public/{$dir}/ vs rute '{$uri}'")->implode('; ')
                    .'. Pindahkan foldernya, mis. ke bawah public/img/.'
        );
    }

    /**
     * Folder `.git` di dalam direktori yang dilayani web.
     *
     * Kelas paparan yang sudah lama dikenal: selama web server tidak memblokir
     * berkas berawalan titik, `https://domain/<path>/.git/config` bisa diambil
     * siapa pun, dan dari sana seluruh isi repositori dapat disusun ulang.
     *
     * Bukan kemungkinan teoretis di proyek ini: `public/anotasi/` adalah klon
     * repositori tersendiri, jadi `.git`-nya memang ada di sana.
     */
    private function periksaRepoDiPublic(bool $produksi): void
    {
        $ditemukan = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(public_path(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && $item->getFilename() === '.git') {
                $ditemukan[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $item->getPathname());
            }
        }

        // Peringatan di mesin pengembang, kegagalan di server. Klon repositori
        // bersarang itu wajar saat mengembangkan dan tidak membahayakan apa pun
        // selama tidak terbuka ke publik; yang berbahaya adalah ia ikut
        // ter-deploy. `build-release.sh` sudah mengecualikannya dari paket.
        $this->nilai(
            'Tidak ada folder .git di dalam public/',
            empty($ditemukan),
            empty($ditemukan)
                ? ''
                : 'Ditemukan: '.implode(', ', $ditemukan).
                  '. Pastikan tidak ikut ke server (build-release.sh sudah mengecualikannya), '.
                  'atau blokir berkas berawalan titik di web server.',
            wajib: $produksi
        );
    }

    private function periksaBasisData(): void
    {
        try {
            DB::connection()->getPdo();
            $this->nilai('Koneksi basis data', true, '');
        } catch (Throwable $e) {
            $this->nilai('Koneksi basis data', false, 'Periksa DB_* di .env. '.mb_substr($e->getMessage(), 0, 80));

            return;
        }

        // Dibandingkan langsung terhadap tabel `migrations`, bukan lewat API
        // internal migrator: yang terakhir berubah antar versi Laravel dan
        // melempar diam-diam, sehingga pemeriksaannya berubah menjadi catatan
        // "tidak bisa dibaca" alih-alih menjawab pertanyaannya.
        try {
            $berkas = collect(glob(database_path('migrations/*.php')) ?: [])
                ->map(fn ($path) => basename($path, '.php'));

            $sudah = DB::table('migrations')->pluck('migration');
            $belum = $berkas->diff($sudah);

            $this->nilai(
                'Migrasi sudah dijalankan',
                $belum->isEmpty(),
                "Masih ada {$belum->count()} migrasi tertunda. Jalankan: php artisan migrate --force"
            );
        } catch (Throwable $e) {
            $this->nilai(
                'Migrasi sudah dijalankan',
                false,
                'Tabel migrations tidak terbaca. Jalankan: php artisan migrate --force'
            );
        }

        // Charset yang salah baru ketahuan saat komentar ber-emoji disimpan,
        // dan saat itu datanya sudah telanjur terpotong.
        if (config('database.default') === 'mysql') {
            $charset = config('database.connections.mysql.charset');

            $this->nilai(
                'Charset utf8mb4',
                $charset === 'utf8mb4',
                "Charset sekarang '{$charset}'. Emoji pada komentar tidak akan tersimpan.",
                wajib: false
            );
        }
    }

    private function periksaAntrean(): void
    {
        $this->nilai(
            'QUEUE_CONNECTION bukan sync',
            config('queue.default') !== 'sync',
            'Dengan sync, analisis berjalan di dalam permintaan web dan akan kehabisan waktu.'
        );

        try {
            $menunggu = DB::table('jobs')->count();
            $gagal = DB::table('failed_jobs')->count();

            $this->catatan("Job menunggu: {$menunggu}, job gagal: {$gagal}");

            // Job menumpuk tanpa berkurang adalah tanda paling jelas worker
            // tidak berjalan, dan itu penyebab nomor satu analisis yang macet.
            if ($menunggu > 10) {
                $this->peringatkan(
                    "{$menunggu} job menunggu. Pastikan `php artisan queue:work` benar-benar berjalan ".
                    '(lihat: sudo supervisorctl status).'
                );
            }
        } catch (Throwable) {
            $this->catatan('Tabel antrean belum bisa dibaca.');
        }
    }

    private function periksaLayananNlp(NLPApiService $nlp): void
    {
        $health = $nlp->health();

        if ($health === null) {
            $this->nilai(
                'Layanan NLP merespons',
                false,
                'Tidak ada balasan dari '.config('services.nlp_api.url').
                '. Seluruh analisis akan gagal sampai layanan ini hidup.'
            );

            return;
        }

        $this->nilai('Layanan NLP merespons', true, '');

        // `/health` di atas sengaja terbuka tanpa kunci, jadi lulusnya tidak
        // membuktikan apa pun soal kunci. Tanpa pemeriksaan ini, kunci yang
        // salah membuat perintah ini hijau sementara SETIAP analisis ditolak 401.
        $kunci = $nlp->checkApiKey();

        $this->nilai(
            'Kunci API diterima layanan'.($kunci['status'] ? " (HTTP {$kunci['status']})" : ''),
            $kunci['ok'],
            $kunci['pesan']
        );

        foreach (['sentiment_retrained' => 'Checkpoint sentimen', 'aspect_retrained' => 'Checkpoint aspek'] as $kunci => $label) {
            $nilai = $nlp->healthModelValue($health, $kunci);

            $this->catatan($label.': '.($nilai === true ? 'hasil pelatihan ulang' : 'bobot dasar'));
        }
    }

    // ------------------------------------------------------------- tampilan

    private function bagian(string $judul): void
    {
        $this->line("  <fg=cyan;options=bold>{$judul}</>");
    }

    /**
     * Setiap model di GEMINI_MODEL benar-benar bisa dipanggil.
     *
     * Peringatan, bukan kegagalan: tanpa AI aplikasi tetap utuh. Tetapi model
     * yang pensiun di daftar cadangan pernah membuat pengguna melihat "Model
     * gemini-1.5-pro tidak tersedia" ketika model utama hanya sibuk sesaat.
     */
    private function periksaGemini(): void
    {
        $llm = app(\App\Services\LlmService::class);

        if (! $llm->isConfigured()) {
            $this->catatan('GEMINI_API_KEY kosong: tombol "Jelaskan dengan AI" mati, sisa aplikasi tetap berjalan.');

            return;
        }

        $hasil = $llm->periksaModel();

        foreach ($hasil as $model => $kode) {
            $this->nilai(
                "Model {$model} dapat dipakai (HTTP ".($kode ?: 'tak terjangkau').')',
                $kode === 200,
                $kode === 404
                    ? 'Model ini pensiun atau tidak tersedia untuk kunci ini. Hapus dari GEMINI_MODEL.'
                    : 'Periksa GEMINI_API_KEY, kuota, atau koneksi keluar server.',
                wajib: false
            );
        }

        $this->nilai(
            'Minimal satu model Gemini berfungsi',
            in_array(200, $hasil, true),
            'Semua kandidat gagal: fitur "Jelaskan dengan AI" tidak akan berjalan.',
            wajib: false
        );
    }

    private function nilai(string $label, bool $lulus, string $saran, bool $wajib = true): void
    {
        if ($lulus) {
            $this->line("    <fg=green>OK  </> {$label}");

            return;
        }

        if ($wajib) {
            $this->gagal++;
            $this->line("    <fg=red;options=bold>GAGAL</> {$label}");
        } else {
            $this->peringatan++;
            $this->line("    <fg=yellow>PERINGATAN</> {$label}");
        }

        if ($saran !== '') {
            $this->line("         <fg=gray>{$saran}</>");
        }
    }

    private function catatan(string $teks): void
    {
        $this->line("    <fg=gray>--  {$teks}</>");
    }

    private function peringatkan(string $teks): void
    {
        $this->peringatan++;
        $this->line("    <fg=yellow>PERINGATAN</> {$teks}");
    }

    private function ringkasan(): int
    {
        $this->newLine();

        if ($this->gagal > 0) {
            $this->line("  <fg=red;options=bold>{$this->gagal} pemeriksaan GAGAL</>, {$this->peringatan} peringatan.");
            $this->line('  <fg=gray>Perbaiki yang gagal sebelum aplikasi dipakai.</>');
            $this->newLine();

            return self::FAILURE;
        }

        if ($this->peringatan > 0) {
            $this->line("  <fg=yellow;options=bold>Lulus dengan {$this->peringatan} peringatan.</>");
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line('  <fg=green;options=bold>Semua pemeriksaan lulus.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
