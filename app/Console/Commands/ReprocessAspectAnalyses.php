<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTextAnalysis;
use App\Models\TextAnalysis;
use Illuminate\Console\Command;

/**
 * Jalankan ulang analisis aspek/gabungan yang hasilnya dibuat sebelum dua
 * perbaikan berikut di sisi NLP API:
 *
 * 1. Sentimen per-aspek dulu dihitung dari KALIMAT PENUH, sehingga seluruh
 *    aspek dalam satu kalimat mendapat polaritas yang sama. Kini dihitung dari
 *    klausa tempat aspek berada.
 * 2. Teks kosong dulu dibuang dari daftar sehingga penjajaran indeks bergeser
 *    dan setiap baris setelah baris kosong tersimpan dengan teks yang salah.
 *
 * Berbeda dengan analysis:repair-distribution yang bisa menghitung ulang dari
 * data tersimpan, angka di sini HARUS diambil ulang dari model - polaritas
 * per-klausa tidak dapat direkonstruksi dari hasil lama. Karena itu perintah
 * ini mengantre ulang pekerjaannya, bukan menambal basis data.
 *
 * Syarat: service NLP berjalan dan `php artisan queue:work` aktif.
 */
class ReprocessAspectAnalyses extends Command
{
    protected $signature = 'analysis:reprocess-aspect
                            {--apply : Antrekan pekerjaannya (tanpa flag ini hanya menampilkan laporan)}
                            {--id=* : Batasi pada id analisis tertentu}
                            {--all : Sertakan juga analisis yang tidak terdeteksi bermasalah}';

    protected $description = 'Antre ulang analisis aspek/gabungan yang sentimen per-aspeknya dihitung dengan cara lama';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyIds = array_filter((array) $this->option('id'));

        $query = TextAnalysis::with('result')
            ->where('status', 'completed')
            ->whereIn('analysis_type', ['aspect', 'combined']);

        if ($onlyIds) {
            $query->whereIn('id', $onlyIds);
        }

        $candidates = $query->orderBy('id')->get();

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada analisis aspek/gabungan yang selesai.');
            return self::SUCCESS;
        }

        $rows = [];
        $targets = [];

        foreach ($candidates as $analysis) {
            $reason = $this->needsReprocessing($analysis);

            if ($reason === null && ! $this->option('all')) {
                continue;
            }

            $targets[] = $analysis;
            $rows[] = [
                $analysis->id,
                $analysis->analysis_type,
                count($this->asArray($analysis->raw_data)),
                count($this->asArray($analysis->result?->aspect_results)),
                $reason ?? 'diminta lewat --all',
            ];
        }

        if (! $rows) {
            $this->info('Semua analisis sudah memakai perhitungan terbaru.');
            return self::SUCCESS;
        }

        $this->table(['ID', 'Tipe', 'Teks', 'Aspek', 'Alasan'], $rows);

        if (! $apply) {
            $this->warn(sprintf(
                '%d analisis perlu diproses ulang. Jalankan dengan --apply untuk mengantrekannya.',
                count($targets)
            ));
            return self::SUCCESS;
        }

        foreach ($targets as $analysis) {
            $analysis->update([
                'status' => 'pending',
                'progress' => 0,
                'current_step' => 'Menunggu proses ulang...',
                'error_message' => null,
            ]);

            ProcessTextAnalysis::dispatch($analysis);
        }

        $this->info(sprintf('%d analisis diantrekan. Pastikan queue:work berjalan.', count($targets)));

        return self::SUCCESS;
    }

    /**
     * Normalkan kolom JSON menjadi array.
     *
     * Sebagian baris lama menyimpan JSON yang ter-encode dua kali sehingga
     * cast Eloquent mengembalikan string, bukan array.
     */
    private function asArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Kembalikan alasan analisis perlu diproses ulang, atau null bila hasilnya
     * sudah memakai perhitungan terbaru.
     */
    private function needsReprocessing(TextAnalysis $analysis): ?string
    {
        $result = $analysis->result;

        if (! $result) {
            return 'tidak ada AnalysisResult';
        }

        // Penanda versi ditulis ProcessTextAnalysis setiap kali hasil disimpan.
        // Menebak dari isi data tidak andal: analisis yang sudah benar pun bisa
        // tidak memiliki kelas netral sama sekali bila kalimatnya memang tegas.
        $version = (int) ($this->asArray($result->metrics)['pipeline_version'] ?? 0);

        if ($version < ProcessTextAnalysis::PIPELINE_VERSION) {
            return sprintf(
                'pipeline v%d (terbaru v%d)',
                $version,
                ProcessTextAnalysis::PIPELINE_VERSION
            );
        }

        $aspects = $this->asArray($result->aspect_results);

        if (! $aspects) {
            return 'aspect_results kosong';
        }

        // Penjajaran indeks: panjang keluaran harus sama dengan jumlah teks.
        $rawCount = count($this->asArray($analysis->raw_data));
        $documentAspects = $this->asArray($result->document_aspects);

        if ($documentAspects && $rawCount && count($documentAspects) !== $rawCount) {
            return sprintf('penjajaran bergeser (%d != %d)', count($documentAspects), $rawCount);
        }

        return null;
    }
}
