<?php

namespace App\Console\Commands;

use App\Services\AutoRetrainService;
use Illuminate\Console\Command;

/**
 * Pemeriksaan manual, untuk keperluan operasional.
 *
 * Pelatihan ulang otomatis dipicu saat pengguna menyimpan koreksi; itu jalur
 * satu-satunya yang dibutuhkan pemasangan biasa, dan tidak menuntut cron atau
 * penjadwal apa pun. Perintah ini hanya menutup satu celah sempit: koreksi
 * yang sudah melewati ambang tetapi tertahan jeda, lalu tidak ada lagi yang
 * mengoreksi sesudahnya - tanpa perintah ini, koreksi itu menganggur sampai
 * ada koreksi berikutnya.
 *
 * Bisa dijadwalkan bila memang diinginkan, tetapi sengaja tidak dipasang di
 * routes/console.php supaya aplikasi tidak diam-diam bergantung pada penjadwal
 * yang mungkin tidak pernah dijalankan.
 */
class AutoRetrainCommand extends Command
{
    protected $signature = 'analysis:auto-retrain
                            {--dry-run : Tampilkan keputusannya tanpa mengantrekan apa pun}';

    protected $description = 'Picu pelatihan ulang bila koreksi baru sudah mencapai ambang';

    public function handle(AutoRetrainService $auto): int
    {
        $status = $auto->status();

        $this->line('Koreksi baru sejak pelatihan terakhir : '.$status['new_corrections']);
        $this->line('Ambang                                : '.$status['threshold']);
        $this->line('Sisa jeda                             : '.$status['cooldown_hours_left'].' jam');
        $this->line('Sedang melatih                        : '.($status['running'] ? 'ya' : 'tidak'));

        if ($this->option('dry-run')) {
            $this->comment('Mode dry-run, tidak ada yang diantrekan.');

            return self::SUCCESS;
        }

        $hasil = $auto->run();

        if ($hasil['triggered']) {
            $this->info($hasil['reason']);
        } else {
            $this->comment('Tidak dipicu: '.$hasil['reason']);
        }

        return self::SUCCESS;
    }
}
