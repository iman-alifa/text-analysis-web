<?php

namespace App\Console\Commands;

use App\Models\EvaluationDataset;
use App\Models\EvaluationItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Satukan dua label aspek emas menjadi satu.
 *
 * Dibuat untuk menyeragamkan penamaan orang: golden dataset memuat
 * `prabowo subianto` (7 label) di samping `prabowo` (25 label), sementara
 * koreksi active learning tidak pernah sekali pun memakai nama lengkap. Dua
 * bentuk untuk satu rujukan membuat model dihukum karena memakai bentuk yang
 * salah satu sisi anggap benar.
 *
 * Yang dilakukan hanya MENGGABUNGKAN label yang sudah ada. Perintah ini tidak
 * pernah mengarang label baru dan tidak menyentuh teks, jadi `content_hash`
 * dataset tetap sah - yang berubah labelnya, bukan isinya.
 *
 * Bawaannya mode rencana, sama seperti `evaluation:audit-aspects`: tanpa
 * `--apply` tidak ada satu baris pun yang berubah.
 */
class EvaluationMergeAspectLabel extends Command
{
    protected $signature = 'evaluation:merge-aspect-label
                            {dataset : ID golden dataset}
                            {--from=* : Label yang digabungkan (boleh diulang)}
                            {--to= : Label tujuan}
                            {--apply : Terapkan perubahan (tanpa ini hanya menampilkan rencana)}';

    protected $description = 'Satukan label aspek emas, mis. "prabowo subianto" menjadi "prabowo"';

    public function handle(): int
    {
        $dataset = EvaluationDataset::find($this->argument('dataset'));

        if (! $dataset) {
            $this->error('Golden dataset tidak ditemukan.');

            return self::FAILURE;
        }

        $tujuan = mb_strtolower(trim((string) $this->option('to')));

        $asal = array_values(array_filter(array_map(
            fn ($nilai) => mb_strtolower(trim((string) $nilai)),
            (array) $this->option('from')
        )));

        if ($tujuan === '' || empty($asal)) {
            $this->error('Sebutkan --from dan --to. Contoh: --from="prabowo subianto" --to=prabowo');

            return self::FAILURE;
        }

        if (in_array($tujuan, $asal, true)) {
            $this->error('Label tujuan tidak boleh ikut disebut sebagai --from.');

            return self::FAILURE;
        }

        $items = $dataset->items()->whereNotNull('gold_aspects')->get();

        $rencana = [];
        $sudahPunyaTujuan = 0;

        foreach ($items as $item) {
            $labelAsli = array_map(
                fn ($a) => mb_strtolower(trim((string) $a)),
                (array) $item->gold_aspects
            );

            $cocok = array_values(array_intersect($labelAsli, $asal));

            if (empty($cocok)) {
                continue;
            }

            // array_unique setelah penggantian: baris yang SUDAH punya label
            // tujuan tidak boleh berakhir dengan dua salinan. `array_values`
            // supaya kuncinya tetap berurutan saat disimpan sebagai JSON.
            $baru = array_values(array_unique(array_map(
                fn ($a) => in_array($a, $asal, true) ? $tujuan : $a,
                $labelAsli
            )));

            if (in_array($tujuan, $labelAsli, true)) {
                $sudahPunyaTujuan++;
            }

            $rencana[] = [
                'item' => $item,
                'sebelum' => $labelAsli,
                'sesudah' => $baru,
                'cocok' => $cocok,
            ];
        }

        if (empty($rencana)) {
            $this->info('Tidak ada baris yang memuat label '.implode(', ', $asal).'.');

            return self::SUCCESS;
        }

        $this->tampilkanRencana($rencana, $asal, $tujuan, $sudahPunyaTujuan);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Mode rencana. Jalankan ulang dengan --apply untuk menerapkannya.');

            return self::SUCCESS;
        }

        if ($dataset->runs()->exists()) {
            $this->warn(
                'Dataset ini sudah punya '.$dataset->runs()->count().' run evaluasi. Mengubah label emas '
                .'membuat run itu tidak lagi sebanding dengan run sesudahnya - ukur ulang seluruh iterasi.'
            );
        }

        $diubah = $this->terapkan($rencana);

        $dataset->update([
            'label_changes' => array_merge((array) $dataset->label_changes, [[
                'from' => $asal,
                'to' => $tujuan,
                'rows' => $diubah,
                'merged_into_existing' => $sudahPunyaTujuan,
                'at' => now()->toIso8601String(),
            ]]),
        ]);

        $this->info("{$diubah} baris diperbarui.");
        $this->newLine();
        $this->comment('Bentuk dasarnya sengaja dikosongkan. Jalankan berikutnya:');
        $this->line('  php artisan evaluation:normalize-aspects --dataset='.$dataset->id);
        $this->line('  lalu ukur ulang seluruh iterasi dengan evaluation:run.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{item: EvaluationItem, sebelum: array, sesudah: array, cocok: array}>  $rencana
     */
    private function tampilkanRencana(array $rencana, array $asal, string $tujuan, int $sudahPunyaTujuan): void
    {
        $this->info(sprintf(
            '%d baris memuat %s dan akan digabungkan ke "%s".',
            count($rencana),
            implode(', ', array_map(fn ($a) => '"'.$a.'"', $asal)),
            $tujuan
        ));

        if ($sudahPunyaTujuan > 0) {
            $this->line(sprintf(
                '  %d di antaranya sudah punya "%s" - penggabungannya menghasilkan satu label, bukan duplikat.',
                $sudahPunyaTujuan,
                $tujuan
            ));
        }

        $this->newLine();

        $baris = [];
        foreach (array_slice($rencana, 0, 15) as $satu) {
            $baris[] = [
                $satu['item']->id,
                mb_substr((string) $satu['item']->text, 0, 44),
                implode(', ', $satu['sebelum']),
                implode(', ', $satu['sesudah']),
            ];
        }

        $this->table(['ID', 'Teks', 'Sebelum', 'Sesudah'], $baris);

        if (count($rencana) > 15) {
            $this->line('  ... dan '.(count($rencana) - 15).' baris lain.');
        }
    }

    /**
     * @param  array<int, array{item: EvaluationItem, sesudah: array}>  $rencana
     */
    private function terapkan(array $rencana): int
    {
        $diubah = 0;

        DB::transaction(function () use ($rencana, &$diubah) {
            foreach ($rencana as $satu) {
                $satu['item']->update([
                    'gold_aspects' => $satu['sesudah'],
                    // Dikosongkan supaya `evaluation:normalize-aspects` mengisi
                    // ulang bentuk dasarnya lewat stemmer yang sama dengan yang
                    // dipakai model. Selama kosong, HoldoutEvaluationService
                    // MENOLAK menghitung metrik aspek - itu disengaja: lebih
                    // baik gagal keras daripada membandingkan bentuk dasar lama
                    // dengan label yang sudah berubah.
                    'gold_aspects_normalized' => null,
                ]);

                $diubah++;
            }
        });

        return $diubah;
    }
}
