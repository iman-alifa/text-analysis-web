<?php

namespace App\Console\Commands;

use App\Models\TextAnalysis;
use App\Services\RetrainingService;
use Illuminate\Console\Command;

/**
 * Latih ulang memakai koreksi SATU analisis.
 *
 * Dibuat karena studi kasus memisahkan korpus per lembaga, dan pelatihan
 * otomatis dimatikan selama studi kasus supaya waktunya ditentukan peneliti -
 * bukan tersulut sendiri di tengah anotasi.
 *
 * Putaran pertama studi kasus gagal justru di sini: 218 koreksi tersimpan,
 * hanya 19 yang dilatihkan, dan checkpoint tetap disimpan karena set
 * validasinya (4 baris) terlalu kecil untuk menolak apa pun.
 *
 * Penjaganya sendiri **tidak** tinggal di perintah ini melainkan di
 * `RetrainingService`, supaya tombol di `/admin/training` menolak hal yang sama.
 * Selama penjaganya hanya ada di sini, UI bisa memicu pelatihan yang perintah
 * ini tolak - dan itu persis yang terjadi pada iterasi 1.
 */
class RetrainAnalysis extends Command
{
    protected $signature = 'retrain:analysis
                            {analysis? : ID analisis; kosongkan untuk melihat daftarnya}
                            {--model=both : sentiment|aspect|both}
                            {--epochs=3 : Jumlah epoch}
                            {--force : Jalankan walau koreksinya di bawah ambang kelayakan, atau iterasi sebelumnya belum diukur}';

    protected $description = 'Latih ulang model memakai koreksi satu analisis';

    public function handle(RetrainingService $retraining): int
    {
        $id = $this->argument('analysis');

        if (! $id) {
            return $this->tampilkanDaftar($retraining);
        }

        $analysis = TextAnalysis::find($id);

        if (! $analysis) {
            $this->error('Analisis tidak ditemukan.');

            return self::FAILURE;
        }

        $model = (string) $this->option('model');

        // Yang dilatih adalah SELURUH kolam; analisisnya hanya dicatat sebagai
        // pemicu putaran. Keduanya ditampilkan supaya tidak ada yang mengira
        // angka lembaga itulah yang dilatihkan.
        $kolam = $retraining->payloads($retraining->correctedItems());
        $ditahan = $retraining->heldOutCount();
        $sumbangan = $retraining->payloads($retraining->contributionOf((int) $analysis->id));

        $this->info("Putaran dipicu oleh analisis #{$analysis->id} - {$analysis->title}");
        $this->table(['Muatan', 'Sumbangan analisis ini', 'Kolam (yang dilatih)'], [
            ['Sentimen', count($sumbangan['sentiment']), count($kolam['sentiment'])],
            ['Aspek', count($sumbangan['aspect']), count($kolam['aspect'])],
            ['Ditahan golden dataset', '-', $ditahan],
        ]);
        $this->comment('Pelatihan memakai seluruh kolam - protokol active learning baku. '
            .'Analisis di atas dicatat sebagai asal putaran, bukan sebagai penyaring data latih.');

        $hasil = $retraining->dispatch(
            $model,
            (int) $this->option('epochs'),
            null,
            (int) $analysis->id,
            (bool) $this->option('force'),
        );

        if (! empty($hasil['error'])) {
            $this->error($hasil['error']);

            if (! $this->option('force')) {
                $this->line('Pakai --force bila memang disengaja.');
            }

            return self::FAILURE;
        }

        $this->info('Diantrekan: '.implode(', ', $hasil['dispatched']));

        foreach ($hasil['skipped'] as $dilewati) {
            $this->warn('Dilewati: '.$dilewati);
        }

        $this->newLine();
        $this->comment('Pastikan `php artisan queue:work` berjalan. Ukur iterasi berikutnya '
            .'dengan `evaluation:run` SETELAH pelatihan selesai - bobot lama tertimpa, jadi '
            .'iterasi yang belum diukur tidak bisa diukur belakangan.');

        return self::SUCCESS;
    }

    private function tampilkanDaftar(RetrainingService $retraining): int
    {
        $baris = [];
        $ambang = RetrainingService::MIN_LAYAK;

        foreach (TextAnalysis::orderBy('id')->get() as $analysis) {
            $muatan = $retraining->payloads($retraining->contributionOf((int) $analysis->id));

            $baris[] = [
                $analysis->id,
                mb_substr($analysis->title, 0, 32),
                count($muatan['sentiment']),
                count($muatan['aspect']),
            ];
        }

        if (empty($baris)) {
            $this->warn('Belum ada analisis.');

            return self::SUCCESS;
        }

        // Kolamnya satu, jadi "siap / belum cukup" adalah keadaan kolam - bukan
        // keadaan tiap baris. Kolom status per analisis dulu membaca seolah
        // tiap lembaga dinilai sendiri-sendiri, dan itu justru anggapan yang
        // dibetulkan oleh protokol kolam.
        $kolam = $retraining->payloads($retraining->correctedItems());
        $besarKolam = $retraining->sampleCount($kolam);

        $this->table(['ID', 'Judul', 'Sentimen', 'Aspek'], $baris);

        $this->newLine();
        $this->line(sprintf(
            'Kolam koreksi: <options=bold>%d sentimen</>, <options=bold>%d aspek</> - inilah yang dilatihkan tiap putaran.',
            count($kolam['sentiment']),
            count($kolam['aspect'])
        ));
        $this->line($besarKolam >= $ambang
            ? "Status: <fg=green>siap</> (kolam {$besarKolam} >= ambang {$ambang})"
            : "Status: <fg=yellow>belum cukup</> (kolam {$besarKolam} < ambang {$ambang})");
        $this->comment('Jalankan: php artisan retrain:analysis <ID> - ID menandai lembaga '
            .'yang baru masuk kolam pada putaran ini, dan dicatat di riwayat. Data latihnya tetap seluruh kolam.');

        return self::SUCCESS;
    }
}
