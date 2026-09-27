<?php

namespace App\Console\Commands;

use App\Services\RetrainDeferralService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Padanan perintah untuk panel "Fase Evaluasi Kuesioner" di /admin/training.
 */
class RetrainDeferral extends Command
{
    protected $signature = 'retrain:tunda
        {aksi=status : status | nyalakan | matikan | putar-ulang | hentikan | lanjutkan}';

    protected $description = 'Tunda pelatihan otomatis selama fase evaluasi kuesioner, lalu putar ulang pemicunya';

    public function handle(RetrainDeferralService $tunda): int
    {
        try {
            match ($this->argument('aksi')) {
                'nyalakan' => $tunda->setAktif(true),
                'matikan' => $tunda->setAktif(false),
                'putar-ulang' => $tunda->mulai(),
                'hentikan' => $tunda->hentikan(),
                // Untuk melanjutkan rantai bila sempat terhenti, mis. evaluasi
                // gagal lalu dijalankan ulang manual lewat `evaluation:run`.
                'lanjutkan' => $this->info('Lanjutkan: '.$tunda->lanjutkan()),
                'status' => null,
                default => throw new RuntimeException('Aksi tidak dikenal: '.$this->argument('aksi')),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $status = $tunda->status();

        $this->table(['', ''], [
            ['Mode tunda', $status['tunda'] ? 'AKTIF' : 'mati'],
            ['Pemutaran ulang', $status['putar_ulang'] ? 'berjalan' : 'tidak'],
            ['Tertunda', $status['tertunda']],
            ['Diputar ulang', $status['dijalankan']],
            ['Dilewati', $status['dilewati']],
        ]);

        if ($status['tertunda'] > 0 && ! $status['putar_ulang'] && $status['syarat']) {
            $this->line('Belum bisa diputar ulang:');
            foreach ($status['syarat'] as $syarat) {
                $this->line('  - '.$syarat);
            }
        }

        if ($status['riwayat']->isNotEmpty()) {
            $this->table(
                ['#', 'Terpicu', 'Koreksi baru', 'Kolam', 'Status', 'Keterangan'],
                $status['riwayat']->map(fn ($r) => [
                    $r->id,
                    $r->cutoff_at->format('Y-m-d H:i'),
                    $r->new_corrections,
                    $r->pool_size,
                    $r->status,
                    mb_strimwidth((string) $r->message, 0, 60, '...'),
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}
