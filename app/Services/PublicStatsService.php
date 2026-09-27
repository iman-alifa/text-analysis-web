<?php

namespace App\Services;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use Illuminate\Support\Facades\Cache;

/**
 * Angka yang tampil di halaman publik (halaman depan dan layout login).
 *
 * Angka evaluasi adalah angka MODEL YANG SEDANG DIPAKAI, bukan run terakhir.
 * 27 Sep model aspek dikembalikan ke iterasi 1 karena iterasi 2 lebih buruk;
 * run terakhir mengukur bobot yang sudah diarsipkan. Karena itu:
 *
 * - akurasi sentimen dari run terbaru yang mengukur bobot sentimen aktif,
 * - F1 aspek dari run terbaru yang mengukur bobot aspek aktif - boleh run yang
 *   berbeda, karena ekstraksi aspek tidak memakai model sentimen,
 * - bila bobot aktif belum pernah diukur, angkanya TIDAK tampil; angka run lain
 *   tidak boleh dipakai sebagai angka model aktif,
 * - pembanding F1 aspek dari iterasi 0 pada dataset yang sama,
 * - jumlah putaran dari iterasi tertinggi yang terukur pada dataset itu,
 * - jumlah koreksi dan teks dari tabel yang dipakai aplikasi itu sendiri.
 *
 * Bobot mana yang aktif dan run mana yang mengukurnya: ActiveModelService.
 *
 * Setiap angka boleh null. Pemasangan baru belum punya evaluasi apa pun, dan
 * lebih baik kartunya tidak tampil daripada menampilkan angka karangan.
 *
 * Di-cache sebentar saja (CACHE_DETIK) karena halaman depan terbuka untuk umum;
 * angka tetap mengikuti keadaan terakhir dalam hitungan detik.
 */
class PublicStatsService
{
    public const CACHE_KEY = 'public-stats';

    public const CACHE_DETIK = 60;

    public function snapshot(): array
    {
        // Halaman depan tidak boleh jatuh karena basis data: pemasangan baru
        // yang belum dimigrasi, atau MySQL yang sedang mati, cukup membuat
        // angkanya tidak tampil. Kegagalan tidak di-cache supaya pulih sendiri.
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_DETIK, fn () => $this->hitung());
        } catch (\Throwable $e) {
            report($e);

            return self::kosong();
        }
    }

    public static function kosong(): array
    {
        return [
            'accuracy' => null, 'accuracy_ci' => null, 'test_size' => null,
            'aspect_f1' => null, 'aspect_f1_baseline' => null, 'iterations' => null,
            'accuracy_iteration' => null, 'aspect_iteration' => null,
            'corrections' => 0, 'texts' => 0, 'analyses' => 0, 'measured_at' => null,
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function hitung(): array
    {
        // Test set yang berlaku; bila belum ada yang beku, dataset tempat run
        // terakhir dijalankan.
        $datasetId = EvaluationDataset::ujiAktif()?->id
            ?? EvaluationRun::completed()->latest('completed_at')->value('evaluation_dataset_id');

        $runs = $datasetId
            ? EvaluationRun::completed()
                ->where('evaluation_dataset_id', $datasetId)
                ->orderByDesc('completed_at')->orderByDesc('id')
                ->get()
            : collect();

        // Parsial adalah angka utama ekstraksi aspek; ketat jadi cadangan
        // untuk run lama yang belum menyimpan blok `partial`.
        $f1Aspek = fn (array $m) => ($m['available'] ?? true)
            ? ($m['partial']['micro_f1'] ?? $m['micro_f1'] ?? null)
            : null;

        $model = app(ActiveModelService::class);
        $aktif = $runs->isNotEmpty() ? $model->aktif() : null;

        $runSentimen = $runs->first(fn (EvaluationRun $run) => isset($run->metrics['sentiment']['accuracy'])
            && $model->kunciRun($run, 'sentiment') === $aktif['sentiment']);

        $runAspek = $runs->first(fn (EvaluationRun $run) => $f1Aspek($run->metrics['aspect'] ?? []) !== null
            && $model->kunciRun($run, 'aspect') === $aktif['aspect']);

        $baseline = $runs->sortBy([['iteration', 'asc'], ['completed_at', 'asc']])->first();

        $sentimen = $runSentimen?->metrics['sentiment'] ?? [];
        $aspek = $runAspek?->metrics['aspect'] ?? [];

        return [
            'accuracy' => isset($sentimen['accuracy']) ? (float) $sentimen['accuracy'] : null,
            'accuracy_ci' => $sentimen['accuracy_ci'] ?? null,
            'test_size' => isset($sentimen['n']) ? (int) $sentimen['n'] : null,
            'accuracy_iteration' => $runSentimen ? (int) $runSentimen->iteration : null,
            'aspect_f1' => $runAspek ? (float) $f1Aspek($aspek) : null,
            'aspect_iteration' => $runAspek ? (int) $runAspek->iteration : null,
            'aspect_f1_baseline' => $runAspek && $baseline && $baseline->id !== $runAspek->id
                && $f1Aspek($baseline->metrics['aspect'] ?? []) !== null
                ? (float) $f1Aspek($baseline->metrics['aspect'])
                : null,
            // Putaran yang sudah dijalankan dan terukur, bukan nomor run terakhir.
            'iterations' => $runs->isNotEmpty() ? (int) $runs->max('iteration') : null,
            'corrections' => TrainingItem::where('is_corrected', true)->whereHas('textAnalysis')->count(),
            'texts' => (int) TextAnalysis::where('status', 'completed')->sum('total_records'),
            'analyses' => TextAnalysis::where('status', 'completed')->count(),
            'measured_at' => collect([$runSentimen?->completed_at, $runAspek?->completed_at])->filter()->max(),
        ];
    }

    /** Persen gaya Indonesia: 85.67 -> "85,7%". */
    public static function persen(?float $nilai, int $desimal = 1): ?string
    {
        return $nilai === null ? null : number_format($nilai, $desimal, ',', '.').'%';
    }

    /** Bilangan bulat gaya Indonesia: 8385 -> "8.385". */
    public static function angka(?int $nilai): ?string
    {
        return $nilai === null ? null : number_format($nilai, 0, ',', '.');
    }
}
