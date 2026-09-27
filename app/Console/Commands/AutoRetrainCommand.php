<?php

namespace App\Console\Commands;

use App\Services\AutoRetrainService;
use Illuminate\Console\Command;

/**
 * Mengevaluasi pemicu pelatihan ulang tanpa menunggu koreksi berikutnya.
 *
 * Pelatihan ulang otomatis dipicu saat pengguna menyimpan koreksi. Perintah
 * ini menutup celah koreksi yang sudah melewati ambang tetapi tertahan jeda,
 * lalu tidak ada lagi yang mengoreksi sesudahnya.
 *
 * Dijadwalkan tiap jam di routes/console.php (dulu sengaja tidak; dibalik
 * 27 Sep setelah celah itu benar-benar terjadi di server). Tanpa cron
 * `schedule:run`, aplikasi tetap berjalan seperti sebelumnya: pemicu hanya
 * dievaluasi saat koreksi disimpan.
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
