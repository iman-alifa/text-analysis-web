<?php

namespace App\Console\Commands;

use App\Models\EvaluationDataset;
use App\Models\ModelTraining;
use App\Services\Evaluation\EvaluationComparisonService;
use App\Services\Evaluation\HoldoutEvaluationService;
use Illuminate\Console\Command;
use Throwable;

class EvaluationRun extends Command
{
    protected $signature = 'evaluation:run
        {dataset : ID golden dataset}
        {--label= : Nama iterasi, mis. "Iterasi 1 - setelah 50 koreksi"}
        {--iteration= : Nomor iterasi; bawaannya otomatis menaik}
        {--training= : ID model_trainings yang mendahului run ini}
        {--no-aspects : Lewati evaluasi aspek walau datasetnya punya label aspek}
        {--no-topic : Lewati pencatatan koherensi topik}
        {--topic-corpus=* : ID analisis korpus untuk koherensi; bawaannya korpus sumber golden dataset}
        {--compare-last : Bandingkan dengan run terakhir pada dataset yang sama}';

    protected $description = 'Jalankan golden dataset terhadap model yang sedang aktif';

    public function handle(HoldoutEvaluationService $evaluasi, EvaluationComparisonService $banding): int
    {
        $dataset = EvaluationDataset::find($this->argument('dataset'));

        if (! $dataset) {
            $this->error('Golden dataset tidak ditemukan.');

            return self::FAILURE;
        }

        $sebelumnya = $dataset->runs()->completed()->latest('id')->first();

        $this->info("Menjalankan \"{$dataset->name}\" ({$dataset->item_count} baris) terhadap model aktif...");

        try {
            $run = $evaluasi->run($dataset, [
                'label' => $this->option('label'),
                'iteration' => $this->option('iteration') !== null ? (int) $this->option('iteration') : null,
                'training' => $this->option('training') ? ModelTraining::find($this->option('training')) : null,
                'with_aspects' => ! $this->option('no-aspects'),
                'with_topic' => ! $this->option('no-topic'),
                'topic_corpus_ids' => array_map('intval', (array) $this->option('topic-corpus')) ?: null,
            ]);
        } catch (Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->tampilkanRun($run);

        if ($this->option('compare-last') && $sebelumnya) {
            $this->newLine();
            $this->line('<comment>Dibandingkan dengan '.$sebelumnya->judul().'</comment>');
            $hasil = $banding->compare($sebelumnya, $run);

            if (! ($hasil['valid'] ?? false)) {
                $this->warn($hasil['message']);

                return self::SUCCESS;
            }

            $this->tampilkanPerbandingan($hasil);
        }

        return self::SUCCESS;
    }

    private function tampilkanRun($run): void
    {
        $s = $run->metrics['sentiment'] ?? [];

        if (! ($s['available'] ?? false)) {
            $this->warn('Metrik sentimen tidak tersedia: '.($s['message'] ?? '-'));

            return;
        }

        $this->newLine();
        $this->line("<info>Run #{$run->id} - {$run->judul()}</info>");

        $this->table(['Metrik', 'Nilai'], [
            ['Baris dinilai', $s['n']],
            ['Akurasi', $s['accuracy'].'% (95% CI '.$s['accuracy_ci']['low'].'-'.$s['accuracy_ci']['high'].')'],
            ['Macro-F1', $s['macro_f1'].'% (95% CI '.$s['macro_f1_ci']['low'].'-'.$s['macro_f1_ci']['high'].')'],
            ['Weighted-F1', $s['weighted_f1'].'%'],
            ["Cohen's kappa", $s['cohen_kappa']],
            ['Tebak kelas mayoritas', $s['majority_baseline']['accuracy'].'% ('.$s['majority_baseline']['label'].')'],
            ['Tidak bisa dinilai', $s['unscorable']],
        ]);

        if ($s['accuracy'] <= $s['majority_baseline']['accuracy']) {
            $this->warn('Akurasi model tidak melampaui tebak-kelas-mayoritas. Angka ini belum membuktikan model berguna.');
        }

        $perKelas = [];
        foreach ($s['per_class'] as $label => $skor) {
            $perKelas[] = [$label, $skor['precision'].'%', $skor['recall'].'%', $skor['f1'].'%', $skor['support']];
        }
        $this->table(['Kelas', 'Presisi', 'Recall', 'F1', 'Support'], $perKelas);

        if ($aspek = ($run->metrics['aspect'] ?? null)) {
            if ($aspek['available'] ?? false) {
                // Keempat angkanya dilaporkan bersama, bukan hanya yang ketat.
                //
                // Ketat dan parsial berbeda pada cara mencocokkan nama; macro
                // penuh dan macro bersyarat support berbeda pada aspek mana
                // yang ikut dirata-ratakan. Melaporkan satu saja menyembunyikan
                // pertanyaan yang justru akan ditanyakan penguji - terukur pada
                // golden dataset ini, macro melonjak 15,87 -> 29,12 begitu
                // aspek yang cuma muncul sekali dikeluarkan, yang berarti angka
                // rendah tadi memang didominasi aspek tanpa makna statistik.
                $parsial = $aspek['partial'] ?? [];
                $sering = $aspek['macro_frequent'] ?? [];

                // Parsial adalah angka UTAMA (keputusan pemilik proyek, 24 Sep
                // 2026): konsep yang ditemukan dalam granularitas frasa yang
                // berbeda tetap dihitung ketemu. Ketat tetap dilaporkan sebagai
                // batas bawah.
                $this->table(['Aspek (multi-label)', 'Micro-F1', 'Macro-F1'], [
                    [
                        'Parsial (irisan kata-dasar) - UTAMA',
                        isset($parsial['micro_f1']) ? $parsial['micro_f1'].'%' : '-',
                        isset($parsial['macro_f1']) ? $parsial['macro_f1'].'%' : '-',
                    ],
                    ['Ketat (nama sama persis) - batas bawah', $aspek['micro_f1'].'%', $aspek['macro_f1'].'%'],
                    [
                        'Ketat, aspek support >= '.($sering['min_support'] ?? 2)
                            .' ('.($sering['aspects'] ?? 0).' jenis)',
                        '-',
                        isset($sering['macro_f1']) ? $sering['macro_f1'].'%' : '-',
                    ],
                ]);

                $this->table(['Aspek - lainnya', 'Nilai'], [
                    ['Exact match', $aspek['exact_match'].'%'],
                    ['Hamming loss', $aspek['hamming_loss']],
                    ['TP / FP / FN', $aspek['tp'].' / '.$aspek['fp'].' / '.$aspek['fn']],
                    ['Kecocokan', $aspek['match'] ?? '-'],
                ]);

                if (isset($sering['aspects'], $aspek['per_aspect'])) {
                    $jumlahJenis = count($aspek['per_aspect']);
                    $jarang = $jumlahJenis - (int) $sering['aspects'];

                    if ($jarang > 0) {
                        $this->comment(sprintf(
                            'Macro penuh merata-ratakan %d jenis aspek, %d di antaranya muncul sekali saja - '
                            .'skornya cuma bisa 0 atau 1 dan tidak bermakna statistik. Kolom terakhir '
                            .'mengeluarkannya. Laporkan keduanya; yang bersyarat support tidak menggantikan '
                            .'yang penuh.',
                            $jumlahJenis,
                            $jarang
                        ));
                    }
                }
            } else {
                $this->warn('Metrik aspek tidak dihitung: '.($aspek['message'] ?? '-'));
            }
        }

        $topik = $run->metrics['topic'] ?? null;

        if ($topik && ($topik['available'] ?? false)) {
            $this->table(['Pemodelan topik', 'Nilai'], [
                ['Koherensi c_v', $topik['c_v'].' ('.$topik['c_v_label'].')'],
                ['c_npmi', $topik['c_npmi'] ?? '-'],
                ['Diversity', $topik['diversity'] ?? '-'],
                ['Outlier rate', $topik['outlier_rate'] ?? '-'],
                ['Jumlah topik', $topik['topic_count']],
                ['Korpus', number_format($topik['corpus_texts']).' teks ('.$topik['source'].')'],
            ]);

            $this->comment(
                'Koherensi tidak punya label emas dan tidak sebanding antar korpus, jadi jangan disandingkan '
                .'dengan F1 seolah setara. Diukur pada korpus penuh, bukan pada test set: golden dataset adalah '
                .'sampel acak sehingga koherensinya tertekan. Model topik juga tidak ikut dilatih ulang.'
            );
        } elseif ($topik) {
            $this->warn('Mutu topik tidak dicatat: '.($topik['message'] ?? '-'));
        }
    }

    private function tampilkanPerbandingan(array $hasil): void
    {
        $d = $hasil['deltas'];
        $m = $hasil['mcnemar'];

        $this->table(['Metrik', 'Sebelum', 'Sesudah', 'Selisih'], [
            ['Akurasi', $hasil['before']['accuracy'].'%', $hasil['after']['accuracy'].'%', $this->tanda($d['accuracy'])],
            ['Macro-F1', $hasil['before']['macro_f1'].'%', $hasil['after']['macro_f1'].'%', $this->tanda($d['macro_f1'])],
            ['Weighted-F1', $hasil['before']['weighted_f1'].'%', $hasil['after']['weighted_f1'].'%', $this->tanda($d['weighted_f1'])],
            ['Koherensi c_v', $hasil['before']['coherence'] ?? '-', $hasil['after']['coherence'] ?? '-',
                $d['coherence'] === null ? '-' : ($d['coherence'] > 0 ? '+' : '').number_format($d['coherence'], 4)],
        ]);

        $this->line("McNemar ({$m['method']}): b={$m['b']}, c={$m['c']}, p={$m['p_value']}");
        $this->line($m['significant'] ? "<info>{$m['interpretation']}</info>" : "<comment>{$m['interpretation']}</comment>");

        // Perbandingan berpasangan antar iterasi adalah pengujian berganda.
        // Uji utama ditetapkan di muka pada evaluation:report - iterasi pertama
        // lawan terakhir - dan yang ini deskriptif saja.
        $this->newLine();
        $this->comment(
            'Perbandingan ini DESKRIPTIF. Uji utama yang dilaporkan di naskah adalah iterasi pertama lawan '
            .'iterasi terakhir (php artisan evaluation:report); menguji tiap pasangan iterasi adalah pengujian '
            .'berganda dan akan melebih-lebihkan peluang menemukan hasil "signifikan".'
        );
    }

    private function tanda(?float $nilai): string
    {
        if ($nilai === null) {
            return '-';
        }

        return ($nilai > 0 ? '+' : '').number_format($nilai, 2).' poin';
    }
}
