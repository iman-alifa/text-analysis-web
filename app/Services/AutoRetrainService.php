<?php

namespace App\Services;

use App\Models\ModelTraining;
use App\Models\TrainingItem;
use Illuminate\Support\Facades\Log;

/**
 * Menutup loop active learning tanpa campur tangan admin.
 *
 * Sebelumnya pelatihan ulang hanya bisa dipicu dari `/admin/training`, sehingga
 * seluruh siklus berhenti bila tidak ada admin yang menekan tombol. Kini
 * dievaluasi sendiri setiap kali pengguna menyimpan koreksi, dan juga bisa
 * dijalankan penjadwal.
 *
 * Tiga penjaga supaya tidak melatih ulang terus-menerus:
 *
 * 1. Jumlah koreksi *baru* sejak pelatihan terakhir harus mencapai ambang -
 *    bukan jumlah total, kalau tidak setiap koreksi berikutnya akan memicu
 *    pelatihan lagi selamanya.
 * 2. Jeda minimum sejak pelatihan terakhir. Fine-tuning itu mahal, dan koreksi
 *    biasanya datang berkelompok dalam satu sesi.
 * 3. Tidak menumpuk bila masih ada pelatihan yang berjalan.
 */
class AutoRetrainService
{
    public function __construct(
        private RetrainingService $retraining,
        private RetrainDeferralService $tunda,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('analysis.auto_retrain.enabled', true);
    }

    /**
     * Periksa syarat, lalu picu pelatihan ulang bila terpenuhi.
     *
     * `blocking` memisahkan dua jenis "tidak jadi melatih" yang sangat berbeda
     * artinya bagi orang yang baru menekan Kirim:
     *
     * - **rutin** (belum cukup koreksi, masih dalam jeda, fitur dimatikan) -
     *   keadaan normal, tidak perlu diberitahukan. Menampilkan hitungan menuju
     *   ambang hanya menambah istilah teknis pada alur yang seharusnya
     *   sederhana: baca, koreksi, simpan.
     * - **menghalangi** (di bawah ambang kelayakan, iterasi sebelumnya belum
     *   diukur, pelatihan lain masih berjalan) - koreksinya sudah cukup dan
     *   pengguna berharap putarannya jalan, tetapi ada penjaga yang menolak.
     *   Diam di sini persis kelas kesalahan yang dikeluhkan titipan: satu-
     *   satunya cara tahu adalah membuka basis data.
     *
     * @return array{triggered: bool, blocking: bool, reason: string, new_corrections: int, threshold: int}
     */
    public function run(?int $analysisId = null): array
    {
        // Ambangnya dinilai atas SELURUH label baru di kolam, bukan atas
        // sumbangan satu lembaga. Yang menentukan sebuah putaran layak
        // dijalankan adalah berapa banyak data baru yang akan dilihat model -
        // dan model melihat kolamnya, bukan satu lembaga. Menilai per lembaga
        // akan menolak putaran yang sah hanya karena lembaga terakhir kecil
        // (#6 DPR menyumbang 46, sementara kolamnya sudah ratusan).
        $baru = $this->newCorrectionCount();
        $ambang = (int) config('analysis.auto_retrain.min_new_corrections', 10);

        $tolak = fn (string $alasan, bool $menghalangi = false) => [
            'triggered' => false,
            'blocking' => $menghalangi,
            'reason' => $alasan,
            'new_corrections' => $baru,
            'threshold' => $ambang,
        ];

        if (! $this->enabled()) {
            return $tolak('Pelatihan ulang otomatis dimatikan lewat konfigurasi.');
        }

        // Selama pemicu tertunda diputar ulang, pemicu langsung tidak boleh
        // menyelip di antaranya: kurva yang direkonstruksi akan bercampur
        // dengan putaran yang tidak pernah ditunda.
        if (RetrainDeferralService::putarUlangAktif()) {
            return $tolak('Pemutaran ulang pemicu tertunda sedang berjalan; koreksi ini ikut pada pemicu berikutnya.');
        }

        if (ModelTraining::running()->exists()) {
            return $tolak('Masih ada pelatihan yang berjalan; koreksi ini ikut pada putaran berikutnya.', true);
        }

        if ($baru < $ambang) {
            return $tolak("Koreksi baru {$baru} dari minimal {$ambang}.");
        }

        if (($sisaJeda = $this->cooldownRemaining()) > 0) {
            return $tolak("Masih dalam jeda {$sisaJeda} jam sejak pelatihan terakhir.");
        }

        // Fase evaluasi kuesioner: pemicunya DICATAT, tidak dijalankan.
        // Sengaja diperiksa setelah ambang dan jeda, supaya yang tercatat
        // persis pemicu yang akan terjadi bila pelatihan berjalan langsung.
        // Bukan "menghalangi" bagi pengguna: halaman koreksi tetap diam.
        if (RetrainDeferralService::aktif()) {
            $catatan = $this->tunda->catat($baru, $analysisId);

            return $tolak("Ditunda (fase evaluasi kuesioner), dicatat sebagai pemicu #{$catatan->id}.");
        }

        $hasil = $this->retraining->dispatch(
            (string) config('analysis.auto_retrain.model_type', 'both'),
            (int) config('analysis.auto_retrain.epochs', 3),
            // null = dipicu sistem, bukan orang.
            null,
            $analysisId
        );

        if (! empty($hasil['error'])) {
            // Sampai di sini berarti koreksinya sudah melewati ambang dan
            // penjaga kelayakan RetrainingService-lah yang menolak - itu harus
            // terlihat, bukan hilang ke dalam log.
            return $tolak($hasil['error'], true);
        }

        Log::info('Pelatihan ulang otomatis dipicu: '.implode(', ', $hasil['dispatched']));

        return [
            'triggered' => true,
            'blocking' => false,
            'reason' => 'Dikirim: '.implode(', ', $hasil['dispatched']),
            'new_corrections' => $baru,
            'threshold' => $ambang,
        ];
    }

    /**
     * Koreksi yang MASUK KE KOLAM setelah pelatihan terakhir dimulai.
     *
     * Titik mulainya adalah pelatihan terakhir **mana pun**, bukan pelatihan
     * analisis ini. Itu konsekuensi langsung dari protokol kolam: pelatihan
     * terakhir sudah melatih seluruh kolam, termasuk koreksi lembaga ini, jadi
     * menghitungnya dari "pelatihan lembaga ini" akan menghitung ulang baris
     * yang sudah dilatihkan sebagai "baru" - dan putaran berikutnya terpicu
     * tanpa satu label baru pun ditambahkan.
     *
     * `$analysisId` hanya membatasi baris mana yang dihitung, untuk menjawab
     * "berapa yang lembaga ini sumbangkan sejak putaran terakhir". Ambang
     * pemicunya sendiri dievaluasi atas seluruh kolam - lihat `run()`.
     *
     * Memakai `verified_at`, bukan `updated_at`: baris latih ikut ter-update
     * karena hal lain, dan itu akan terhitung sebagai koreksi baru.
     *
     * Titiknya BATAS KOLAM yang sudah dilatih atau dicatat, bukan waktu
     * pelatihan dibuat - lihat RetrainDeferralService::batasKolamTerakhir().
     * Jeda tetap memakai waktu pelatihan sebenarnya.
     */
    public function newCorrectionCount(?int $analysisId = null): int
    {
        $sejak = RetrainDeferralService::batasKolamTerakhir();

        return TrainingItem::whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->when($analysisId, fn ($query) => $query->where('text_analysis_id', $analysisId))
            ->when($sejak, fn ($query) => $query->where('verified_at', '>', $sejak))
            ->count();
    }

    /**
     * Sisa jeda dalam jam, 0 bila sudah boleh melatih lagi.
     *
     * Global, karena pelatihan itu sendiri kini peristiwa global: satu putaran
     * melatih seluruh kolam, bukan satu lembaga. Jeda per analisis akan
     * membolehkan enam putaran berturut-turut atas kolam yang praktis sama.
     *
     * Untuk rangkaian iterasi yang dijalankan sengaja, jeda ini bukan penjaga
     * yang penting - yang menjaga urutannya adalah penolakan "iterasi
     * sebelumnya belum diukur" di RetrainingService, dan itu tidak bisa
     * dilewati waktu. Setel `ANALYSIS_AUTO_RETRAIN_COOLDOWN=0` bila enam
     * putaran memang dijalankan dalam satu sesi.
     */
    public function cooldownRemaining(): int
    {
        $jeda = (int) config('analysis.auto_retrain.cooldown_hours', 6);

        if ($jeda <= 0) {
            return 0;
        }

        // Pemicu tertunda dihitung sebagai "pelatihan" untuk jeda, sama seperti
        // untuk hitungan koreksi baru.
        $terakhir = RetrainDeferralService::titikTerakhir();

        if (! $terakhir) {
            return 0;
        }

        $boleh = \Illuminate\Support\Carbon::parse($terakhir)->addHours($jeda);

        return $boleh->isFuture() ? (int) ceil(now()->diffInMinutes($boleh) / 60) : 0;
    }

    /**
     * Ringkasan untuk ditampilkan - dipakai halaman admin sebagai pengawasan.
     *
     * Angkanya mengikuti yang dipakai `run()` untuk memutuskan: label baru di
     * seluruh kolam. Menampilkan angka lain dari yang menentukan keputusan akan
     * membuat lencananya berbohong - "50/50" tetapi tidak terpicu.
     */
    public function status(): array
    {
        $baru = $this->newCorrectionCount();
        $ambang = (int) config('analysis.auto_retrain.min_new_corrections', 10);

        return [
            'enabled' => $this->enabled(),
            'new_corrections' => $baru,
            'threshold' => $ambang,
            'remaining' => max($ambang - $baru, 0),
            'progress' => $ambang > 0 ? min((int) round(($baru / $ambang) * 100), 100) : 0,
            'cooldown_hours_left' => $this->cooldownRemaining(),
            'running' => ModelTraining::running()->exists(),
            'pool' => $this->poolSize(),
        ];
    }

    /**
     * Besar kolam koreksi yang akan dilatihkan pada putaran berikutnya.
     *
     * Dihitung dengan query, bukan lewat `RetrainingService::correctedItems()`:
     * halaman admin hanya perlu angkanya, dan memuat ribuan model hanya untuk
     * di-`count()` itu mahal. Bedanya dengan angka yang benar-benar dilatih
     * hanyalah baris yang ditahan golden dataset, dan itu ditampilkan terpisah.
     */
    public function poolSize(): int
    {
        return TrainingItem::whereHas('textAnalysis')
            ->where('is_corrected', true)
            ->count();
    }
}
