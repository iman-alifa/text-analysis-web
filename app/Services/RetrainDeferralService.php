<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\RetrainDeferral;
use App\Models\TrainingItem;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mode tunda pelatihan ulang untuk fase evaluasi kuesioner.
 *
 * Masalahnya: selama uji coba, pengguna nyata diminta langsung mengoreksi
 * hasil analisisnya. Pelatihan otomatis akan terpicu di tengah uji coba,
 * padahal golden dataset untuk domain baru itu baru bisa diambil setelah semua
 * pengguna selesai. Tanpa golden dataset, tidak ada iterasi yang bisa diukur,
 * dan bobot yang tertimpa tidak bisa diukur belakangan.
 *
 * Alurnya:
 *
 * 1. **Tunda.** Setiap kali pelatihan otomatis SEHARUSNYA terpicu, yang
 *    terjadi hanyalah pencatatan: kapan (`cutoff_at`), berapa koreksi baru,
 *    berapa besar kolamnya. Koreksi pengguna tetap tersimpan seperti biasa.
 * 2. **Ambil golden dataset**, anotasi, bekukan, lalu ukur baseline-nya.
 * 3. **Putar ulang.** Tiap pemicu tertunda dijalankan berurutan sebagai satu
 *    putaran pelatihan, dengan kolam DIPOTONG pada `cutoff_at` pemicunya.
 *    Evaluasi otomatis mengukur tiap putaran, lalu putaran berikutnya
 *    dilanjutkan dari sini. Hasilnya kurva active learning pada domain
 *    pengguna nyata, seolah pelatihan berjalan langsung selama uji coba.
 *
 * Mengapa kolam dipotong: protokol kolam melatih dari bobot dasar tetap dengan
 * seed tetap atas SELURUH koreksi. N putaran atas kolam akhir yang sama akan
 * menghasilkan model identik N kali.
 */
class RetrainDeferralService
{
    public const KUNCI_TUNDA = 'retrain.tunda';

    public const KUNCI_PUTAR_ULANG = 'retrain.putar_ulang';

    public function __construct(private RetrainingService $retraining) {}

    // ------------------------------------------------------------- saklar

    /**
     * Statis supaya RetrainingService bisa membacanya tanpa ketergantungan
     * melingkar. Nilai di basis data menang; .env hanya bawaan awal.
     */
    public static function aktif(): bool
    {
        try {
            return (bool) AppSetting::ambil(self::KUNCI_TUNDA, config('analysis.auto_retrain.defer', false));
        } catch (\Throwable) {
            // Tabel belum dimigrasi: perilaku lama.
            return (bool) config('analysis.auto_retrain.defer', false);
        }
    }

    public function setAktif(bool $aktif): void
    {
        AppSetting::simpan(self::KUNCI_TUNDA, $aktif);
    }

    public static function putarUlangAktif(): bool
    {
        try {
            return (bool) (AppSetting::ambil(self::KUNCI_PUTAR_ULANG)['aktif'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------- tunda

    public function catat(int $koreksiBaru, ?int $analysisId = null): RetrainDeferral
    {
        $tunda = RetrainDeferral::create([
            'cutoff_at' => now(),
            'new_corrections' => $koreksiBaru,
            'pool_size' => TrainingItem::whereHas('textAnalysis')->where('is_corrected', true)->count(),
            'text_analysis_id' => $analysisId,
            'model_type' => (string) config('analysis.auto_retrain.model_type', 'both'),
        ]);

        Log::info("Pelatihan otomatis ditunda (#{$tunda->id}): {$koreksiBaru} koreksi baru, kolam {$tunda->pool_size}.");

        return $tunda;
    }

    /**
     * Titik mulai hitungan "koreksi baru" dan jeda: pelatihan terakhir ATAU
     * pemicu tertunda terakhir, mana yang lebih baru.
     *
     * Tanpa ini, selama mode tunda tidak ada pelatihan yang tercatat, sehingga
     * setiap koreksi sesudah ambang pertama akan tercatat sebagai pemicu baru -
     * bukan satu pemicu per kumpulan koreksi seperti bila pelatihan berjalan.
     */
    public static function titikTerakhir(): ?\DateTimeInterface
    {
        $latih = ModelTraining::latest('created_at')->value('created_at');

        try {
            $tunda = RetrainDeferral::latest('cutoff_at')->value('cutoff_at');
        } catch (\Throwable) {
            $tunda = null;
        }

        if (! $latih) {
            return $tunda ? \Illuminate\Support\Carbon::parse($tunda) : null;
        }

        if (! $tunda) {
            return \Illuminate\Support\Carbon::parse($latih);
        }

        return max(\Illuminate\Support\Carbon::parse($latih), \Illuminate\Support\Carbon::parse($tunda));
    }

    /**
     * Batas kolam yang terakhir sudah masuk hitungan: titik mulai hitungan
     * "koreksi baru".
     *
     * Berbeda dari titikTerakhir(), yang dipakai jeda. Pelatihan hasil pemutaran
     * ulang dibuat JAUH setelah pemicunya (26 Sep untuk pemicu 24 Sep) tetapi
     * hanya melatih kolam sampai `cutoff_at` pemicunya. Menghitung dari waktu
     * pembuatannya membuat setiap koreksi di antara keduanya tidak pernah
     * terhitung "baru" - di server 36 koreksi 24 Sep tidak pernah memicu apa
     * pun karena itu.
     *
     * Batas tiap pelatihan: `cutoff_at` pemicu tertunda yang memutarnya, atau
     * waktu pembuatannya bila dijalankan langsung (kolamnya diambil saat itu).
     * Pemicu tertunda yang belum diputar juga dihitung: koreksinya sudah
     * tercatat pada pemicu itu.
     */
    public static function batasKolamTerakhir(): ?\DateTimeInterface
    {
        $calon = [];

        try {
            $cutoffPelatihan = [];

            foreach (RetrainDeferral::whereNotNull('training_ids')->get(['cutoff_at', 'training_ids']) as $tunda) {
                foreach ((array) $tunda->training_ids as $id) {
                    $cutoffPelatihan[(int) $id] = $tunda->cutoff_at;
                }
            }

            if ($tunda = RetrainDeferral::max('cutoff_at')) {
                $calon[] = \Illuminate\Support\Carbon::parse($tunda);
            }
        } catch (\Throwable) {
            // Tabel belum dimigrasi: perilaku lama.
            $cutoffPelatihan = [];
        }

        foreach (ModelTraining::get(['id', 'created_at']) as $latih) {
            $calon[] = \Illuminate\Support\Carbon::parse($cutoffPelatihan[$latih->id] ?? $latih->created_at);
        }

        return $calon ? max($calon) : null;
    }

    // ------------------------------------------------------------- putar ulang

    /**
     * Syarat memulai pemutaran ulang, sebagai daftar alasan penolakan.
     *
     * @return list<string> kosong berarti boleh
     */
    public function syaratPutarUlang(): array
    {
        $masalah = [];

        if (self::aktif()) {
            $masalah[] = 'Mode tunda masih aktif. Matikan dulu supaya pemicu baru tidak bercampur dengan pemutaran ulang.';
        }

        if (self::putarUlangAktif()) {
            $masalah[] = 'Pemutaran ulang sudah berjalan.';
        }

        if (! RetrainDeferral::tertunda()->exists()) {
            $masalah[] = 'Tidak ada pemicu tertunda.';
        }

        if (ModelTraining::running()->exists()) {
            $masalah[] = 'Masih ada pelatihan yang berjalan.';
        }

        // Putaran berikutnya baru dimulai setelah putaran sebelumnya terukur;
        // tanpa evaluasi otomatis rantainya berhenti di putaran pertama.
        if (! config('analysis.evaluation.auto', true)) {
            $masalah[] = 'Evaluasi otomatis dimatikan (ANALYSIS_AUTO_EVALUATE=false); pemutaran ulang membutuhkannya.';
        }

        $dataset = EvaluationDataset::ujiAktif();

        if (! $dataset) {
            $masalah[] = 'Belum ada golden dataset beku. Ambil sampel, anotasi, lalu bekukan dulu.';
        } elseif (! $this->baselineTerukur($dataset)) {
            $masalah[] = "Golden dataset \"{$dataset->name}\" belum diukur sejak dibekukan. "
                .'Jalankan evaluasi baseline (iterasi 0) di /admin/evaluation dulu, supaya putaran pertama punya pembanding.';
        }

        return $masalah;
    }

    public function mulai(): void
    {
        if ($masalah = $this->syaratPutarUlang()) {
            throw new RuntimeException(implode(' ', $masalah));
        }

        AppSetting::simpan(self::KUNCI_PUTAR_ULANG, [
            'aktif' => true,
            'mulai' => now()->toIso8601String(),
            'dataset_id' => EvaluationDataset::ujiAktif()?->id,
            'jumlah' => RetrainDeferral::tertunda()->count(),
        ]);

        Log::info('Pemutaran ulang pemicu tertunda dimulai.');

        $this->lanjutkan();
    }

    public function hentikan(): void
    {
        $status = AppSetting::ambil(self::KUNCI_PUTAR_ULANG, []) ?: [];
        AppSetting::simpan(self::KUNCI_PUTAR_ULANG, array_merge($status, [
            'aktif' => false,
            'dihentikan' => now()->toIso8601String(),
        ]));
    }

    /**
     * Jalankan putaran berikutnya bila saatnya.
     *
     * Dipanggil setelah evaluasi selesai (RunHoldoutEvaluation) dan setelah
     * putaran yang tidak perlu diukur (AutoEvaluationService). Aman dipanggil
     * kapan saja: bila belum saatnya, tidak melakukan apa pun.
     *
     * @return string alasan singkat keputusannya
     */
    public function lanjutkan(): string
    {
        if (! self::putarUlangAktif()) {
            return 'tidak-aktif';
        }

        if (ModelTraining::whereIn('status', ['pending', 'running'])->exists()) {
            return 'putaran-berjalan';
        }

        // Putaran sebelumnya masih menunggu diukur; evaluasi otomatis akan
        // memanggil lagi begitu selesai.
        if ($this->retraining->unmeasuredTraining()) {
            return 'menunggu-evaluasi';
        }

        foreach (RetrainDeferral::tertunda()->orderBy('cutoff_at')->get() as $tunda) {
            $hasil = $this->retraining->dispatch(
                $tunda->model_type ?: 'both',
                (int) config('analysis.auto_retrain.epochs', 3),
                null,
                $tunda->text_analysis_id,
                false,
                $tunda->cutoff_at,
            );

            if (empty($hasil['error'])) {
                $tunda->update([
                    'status' => 'dijalankan',
                    'training_ids' => collect($hasil['trainings'])->pluck('id')->all(),
                    'replayed_at' => now(),
                    'message' => 'Dikirim: '.implode(', ', $hasil['dispatched']),
                ]);

                Log::info("Pemicu tertunda #{$tunda->id} diputar ulang: ".implode(', ', $hasil['dispatched']));

                return 'diputar';
            }

            // Kolam pada titik itu belum layak (mis. di bawah MIN_LAYAK). Bila
            // berjalan langsung pun pemicu ini akan ditolak, jadi dilewati dan
            // dicatat, lalu pemicu berikutnya dicoba.
            $tunda->update([
                'status' => 'dilewati',
                'message' => $hasil['error'],
                'replayed_at' => now(),
            ]);
        }

        $this->hentikan();
        Log::info('Pemutaran ulang selesai: tidak ada lagi pemicu tertunda.');

        return 'selesai';
    }

    public function batalkanSemua(): int
    {
        return RetrainDeferral::tertunda()->update([
            'status' => 'dibatalkan',
            'message' => 'Dibatalkan admin.',
            'replayed_at' => now(),
        ]);
    }

    /**
     * Ringkasan untuk halaman admin dan perintah.
     */
    public function status(): array
    {
        $putar = AppSetting::ambil(self::KUNCI_PUTAR_ULANG, []) ?: [];

        return [
            'tunda' => self::aktif(),
            'putar_ulang' => (bool) ($putar['aktif'] ?? false),
            'putar_ulang_info' => $putar,
            'tertunda' => RetrainDeferral::tertunda()->count(),
            'dijalankan' => RetrainDeferral::where('status', 'dijalankan')->count(),
            'dilewati' => RetrainDeferral::where('status', 'dilewati')->count(),
            'riwayat' => RetrainDeferral::with('textAnalysis')->latest('cutoff_at')->limit(20)->get(),
            'syarat' => $this->syaratPutarUlang(),
        ];
    }

    private function baselineTerukur(EvaluationDataset $dataset): bool
    {
        return EvaluationRun::completed()
            ->where('evaluation_dataset_id', $dataset->id)
            ->when($dataset->frozen_at, fn ($q) => $q->where('completed_at', '>=', $dataset->frozen_at))
            ->exists();
    }
}
