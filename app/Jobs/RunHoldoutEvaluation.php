<?php

namespace App\Jobs;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Services\Evaluation\HoldoutEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjalankan evaluasi hold-out di antrean.
 *
 * Diantrekan, bukan dijalankan langsung dalam permintaan HTTP: satu run
 * mengirim seluruh golden dataset ke NLP API, dan pada beberapa ratus baris
 * itu jauh melewati batas waktu permintaan web - apalagi bila kebetulan ada
 * pelatihan ulang yang sedang memegang lock model.
 */
class RunHoldoutEvaluation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Sejalan dengan ProcessTextAnalysis. Tidak diulang otomatis: run yang
     * gagal separuh jalan lebih baik dilihat dan dijalankan ulang manusia
     * daripada diam-diam menghasilkan dua run untuk iterasi yang sama.
     */
    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * Saat job diantrekan, dibulatkan ke detik. Dipakai failed() untuk
     * menemukan run milik job ini: run-nya dibuat di dalam service, jadi job
     * tidak memegang id-nya.
     */
    private string $diantrekanPada;

    public function __construct(
        private int $datasetId,
        private array $opsi = [],
    ) {
        $this->diantrekanPada = now()->startOfSecond()->toDateTimeString();
    }

    public function handle(HoldoutEvaluationService $evaluasi): void
    {
        $dataset = EvaluationDataset::find($this->datasetId);

        if (! $dataset) {
            Log::warning("Evaluasi dibatalkan: golden dataset {$this->datasetId} tidak ditemukan.");

            return;
        }

        $opsi = $this->opsi;

        if (! empty($opsi['training_id'])) {
            $opsi['training'] = ModelTraining::find($opsi['training_id']);
        }

        try {
            $run = $evaluasi->run($dataset, $opsi);
            Log::info("Evaluasi hold-out selesai: run #{$run->id} pada dataset #{$dataset->id}.");

            // Bila pemicu tertunda sedang diputar ulang, putaran berikutnya
            // baru boleh berjalan setelah putaran ini terukur. Tidak melakukan
            // apa pun di luar pemutaran ulang.
            app(\App\Services\RetrainDeferralService::class)->lanjutkan();
        } catch (Throwable $e) {
            // run-nya sendiri sudah ditandai gagal beserta pesannya oleh
            // HoldoutEvaluationService, jadi di sini cukup dicatat.
            Log::error('Evaluasi hold-out gagal: '.$e->getMessage());
        }
    }

    /**
     * Job dihentikan dari luar: kehabisan waktu, prosesnya dibunuh, atau
     * worker mati lalu job diambil ulang melewati jatah percobaan. Di semua
     * kasus itu blok catch di HoldoutEvaluationService tidak pernah berjalan,
     * dan tanpa ini run-nya tertinggal 'running' selamanya - terjadi di server
     * 26 Sep setelah dua batch aspek kehabisan waktu.
     *
     * Hanya run pada dataset ini yang dibuat sejak job diantrekan. Tidak
     * pernah ada dua run berjalan pada satu dataset (AutoEvaluationService
     * menolaknya), jadi itu pasti run milik job ini.
     */
    public function failed(?Throwable $e): void
    {
        $pesan = 'Evaluasi dihentikan sebelum selesai: '
            .($e?->getMessage() ?: 'job berhenti tanpa pesan')
            .'. Hasil parsial tidak disimpan. Jalankan ulang evaluasinya.';

        $jumlah = EvaluationRun::where('evaluation_dataset_id', $this->datasetId)
            ->whereIn('status', ['pending', 'running'])
            ->where('created_at', '>=', $this->diantrekanPada)
            ->update([
                'status' => 'failed',
                'error_message' => $pesan,
                'completed_at' => now(),
            ]);

        Log::error("Job evaluasi dataset #{$this->datasetId} gagal; {$jumlah} run ditandai gagal. ".($e?->getMessage() ?? ''));
    }
}
