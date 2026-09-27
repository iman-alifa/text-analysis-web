<?php

namespace App\Services;

use App\Jobs\RunHoldoutEvaluation;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use Illuminate\Support\Facades\Log;

/**
 * Evaluasi hold-out otomatis di akhir setiap putaran pelatihan ulang.
 *
 * Menutup loop active learning tanpa campur tangan: koreksi pengguna memicu
 * pelatihan (AutoRetrainService), pelatihan yang selesai memicu pengukuran di
 * sini, dan pengukuran itu membuka penjaga "pelatihan belum diukur" sehingga
 * putaran berikutnya boleh berjalan.
 *
 * Dipanggil RetrainModel setiap kali sebuah job pelatihan berakhir, apa pun
 * statusnya. Keputusan "sudah waktunya mengukur" diambil di sini, bukan di job.
 */
class AutoEvaluationService
{
    public function __construct(
        private RetrainingService $retraining,
        private RetrainDeferralService $tunda,
    ) {}

    /**
     * @return string alasan singkat keputusannya, untuk log dan tes
     */
    public function setelahPelatihan(ModelTraining $training): string
    {
        $alasan = $this->putuskan($training);

        // Putaran yang tidak perlu diukur (bobot tidak berubah) tidak akan
        // pernah memicu RunHoldoutEvaluation, jadi pemutaran ulang pemicu
        // tertunda harus dilanjutkan dari sini. Bila evaluasi diantrekan,
        // lanjutannya dipicu RunHoldoutEvaluation setelah selesai.
        if (in_array($alasan, ['tidak-ada-bobot-baru', 'tidak-ada-test-set'], true)) {
            $this->tunda->lanjutkan();
        }

        return $alasan;
    }

    private function putuskan(ModelTraining $training): string
    {
        if (! config('analysis.evaluation.auto', true)) {
            return 'mati';
        }

        // Satu putaran = job sentimen + job aspek. Yang mengukur adalah job
        // TERAKHIR yang selesai; mengukur di tengah putaran berarti mengukur
        // campuran bobot baru dan lama.
        $masihBerjalan = ModelTraining::whereIn('status', ['pending', 'running'])
            ->whereKeyNot($training->id)
            ->exists();

        if ($masihBerjalan) {
            return 'putaran-belum-selesai';
        }

        $dataset = EvaluationDataset::ujiAktif();

        if (! $dataset) {
            Log::info('Evaluasi otomatis dilewati: belum ada test set beku.');

            return 'tidak-ada-test-set';
        }

        // Tidak ada bobot baru yang belum diukur: misalnya kedua modul ditolak
        // karena metriknya menurun (bobot lama dipertahankan), atau pelatihan
        // gagal. Mengukur lagi hanya menambah titik identik ke kurva.
        if (! $this->retraining->unmeasuredTraining()) {
            return 'tidak-ada-bobot-baru';
        }

        if (EvaluationRun::where('evaluation_dataset_id', $dataset->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists()) {
            return 'sedang-mengukur';
        }

        RunHoldoutEvaluation::dispatch($dataset->id, [
            // Label dibiarkan kosong supaya tampil sebagai "Iterasi N" sesuai
            // penomoran otomatis.
            'label' => null,
            'with_aspects' => true,
            'with_topic' => false,
            // Sengaja tanpa training_id: job yang memicu ini bisa saja yang
            // ditolak atau gagal. Run menautkan dirinya sendiri ke pelatihan
            // terakhir yang SELESAI (HoldoutEvaluationService::pelatihanTerakhir).
            // null = dipicu sistem, sama seperti pelatihan otomatis.
            'triggered_by' => null,
        ]);

        Log::info("Evaluasi otomatis diantrekan pada dataset #{$dataset->id} setelah pelatihan #{$training->id}.");

        return 'diantrekan';
    }
}
