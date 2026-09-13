<?php

namespace App\Services\Evaluation;

use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use RuntimeException;

/**
 * Membandingkan dua run hold-out pada dataset yang sama.
 *
 * Selisih akurasi saja tidak cukup untuk mengklaim model membaik. Pada test
 * set beberapa ratus baris, kenaikan 2-3 poin masih sangat mungkin hanya
 * derau. Kelas ini karena itu menyertakan uji McNemar - uji baku untuk
 * membandingkan dua pengklasifikasi pada test set yang sama (Dietterich,
 * 1998) - yang bekerja pada prediksi berpasangan per baris, bukan pada
 * angka agregat.
 */
class EvaluationComparisonService
{
    /**
     * @return array{
     *   valid: bool, message?: string, before: array, after: array,
     *   deltas: array, mcnemar: array, flips: array
     * }
     */
    public function compare(EvaluationRun $sebelum, EvaluationRun $sesudah): array
    {
        if ($sebelum->evaluation_dataset_id !== $sesudah->evaluation_dataset_id) {
            return [
                'valid' => false,
                'message' => 'Kedua run memakai golden dataset yang berbeda, jadi angkanya tidak sebanding.',
            ];
        }

        if ($sebelum->status !== 'completed' || $sesudah->status !== 'completed') {
            return [
                'valid' => false,
                'message' => 'Hanya run yang selesai yang bisa dibandingkan.',
            ];
        }

        $pasangan = $this->pairedCorrectness($sebelum, $sesudah);

        return [
            'valid' => true,
            'before' => $this->ringkas($sebelum),
            'after' => $this->ringkas($sesudah),
            'deltas' => $this->deltas($sebelum, $sesudah),
            'mcnemar' => $this->mcnemar($pasangan['b'], $pasangan['c'], $pasangan['n']),
            'flips' => [
                'improved' => $pasangan['membaik'],
                'regressed' => $pasangan['memburuk'],
                'improved_count' => $pasangan['c'],
                'regressed_count' => $pasangan['b'],
                'paired_items' => $pasangan['n'],
            ],
        ];
    }

    /**
     * Deret run untuk grafik perkembangan antar iterasi active learning.
     */
    public function learningCurve(EvaluationDataset $dataset): array
    {
        return $dataset->runs()
            ->completed()
            ->orderBy('iteration')
            ->orderBy('id')
            ->get()
            ->map(fn (EvaluationRun $run) => $this->ringkas($run))
            ->values()
            ->all();
    }

    public function ringkas(EvaluationRun $run): array
    {
        $sentimen = $run->metrics['sentiment'] ?? [];
        $aspek = $run->metrics['aspect'] ?? [];
        $topik = $run->metrics['topic'] ?? [];

        return [
            'run_id' => $run->id,
            'iteration' => $run->iteration,
            'label' => $run->judul(),
            'completed_at' => $run->completed_at?->toDateTimeString(),
            'n' => $sentimen['n'] ?? 0,
            'accuracy' => $sentimen['accuracy'] ?? null,
            'accuracy_ci' => $sentimen['accuracy_ci'] ?? null,
            'macro_f1' => $sentimen['macro_f1'] ?? null,
            'macro_f1_ci' => $sentimen['macro_f1_ci'] ?? null,
            'weighted_f1' => $sentimen['weighted_f1'] ?? null,
            'cohen_kappa' => $sentimen['cohen_kappa'] ?? null,
            'majority_baseline' => $sentimen['majority_baseline']['accuracy'] ?? null,
            'aspect_micro_f1' => $aspek['micro_f1'] ?? null,
            'aspect_macro_f1' => $aspek['macro_f1'] ?? null,
            'coherence' => ($topik['available'] ?? false) ? $topik['c_v'] : null,
            'coherence_label' => $topik['c_v_label'] ?? null,
            'topic_count' => $topik['topic_count'] ?? null,
            'model' => $run->model_info['sentiment_source'] ?? null,
            'retrained' => $run->model_info['sentiment_retrained'] ?? null,
            'training_id' => $run->model_training_id,
        ];
    }

    /**
     * Selisih metrik utama, dalam poin persentase.
     */
    private function deltas(EvaluationRun $sebelum, EvaluationRun $sesudah): array
    {
        $a = $sebelum->metrics['sentiment'] ?? [];
        $b = $sesudah->metrics['sentiment'] ?? [];
        $aAspek = $sebelum->metrics['aspect'] ?? [];
        $bAspek = $sesudah->metrics['aspect'] ?? [];
        $aTopik = $sebelum->metrics['topic'] ?? [];
        $bTopik = $sesudah->metrics['topic'] ?? [];

        $selisih = fn ($x, $y) => ($x === null || $y === null) ? null : round($y - $x, 2);

        return [
            'accuracy' => $selisih($a['accuracy'] ?? null, $b['accuracy'] ?? null),
            'macro_f1' => $selisih($a['macro_f1'] ?? null, $b['macro_f1'] ?? null),
            'weighted_f1' => $selisih($a['weighted_f1'] ?? null, $b['weighted_f1'] ?? null),
            'cohen_kappa' => $selisih($a['cohen_kappa'] ?? null, $b['cohen_kappa'] ?? null),
            'aspect_micro_f1' => $selisih($aAspek['micro_f1'] ?? null, $bAspek['micro_f1'] ?? null),
            'aspect_macro_f1' => $selisih($aAspek['macro_f1'] ?? null, $bAspek['macro_f1'] ?? null),
            // Koherensi bukan poin persen, jadi selisihnya dibulatkan lebih
            // halus. Dilaporkan sebagai kontrol: model topik tidak ikut
            // dilatih ulang, jadi angka ini semestinya nyaris diam.
            'coherence' => $this->selisihHalus($aTopik['c_v'] ?? null, $bTopik['c_v'] ?? null),
        ];
    }

    private function selisihHalus(?float $sebelum, ?float $sesudah): ?float
    {
        return ($sebelum === null || $sesudah === null) ? null : round($sesudah - $sebelum, 4);
    }

    /**
     * Pasangkan kebenaran prediksi per baris antara dua run.
     *
     * b = benar di run lama, salah di run baru (regresi)
     * c = salah di run lama, benar di run baru (perbaikan)
     */
    private function pairedCorrectness(EvaluationRun $sebelum, EvaluationRun $sesudah): array
    {
        $lama = $sebelum->items()->with('item:id,text,gold_sentiment,segment')->get()->keyBy('evaluation_item_id');
        $baru = $sesudah->items()->with('item:id,text,gold_sentiment,segment')->get()->keyBy('evaluation_item_id');

        $b = 0;
        $c = 0;
        $n = 0;
        $membaik = [];
        $memburuk = [];

        foreach ($lama as $itemId => $barisLama) {
            $barisBaru = $baru[$itemId] ?? null;

            if (! $barisBaru) {
                continue;
            }

            $n++;
            $benarLama = (bool) $barisLama->sentiment_correct;
            $benarBaru = (bool) $barisBaru->sentiment_correct;

            if ($benarLama === $benarBaru) {
                continue;
            }

            $catatan = [
                'text' => mb_substr((string) ($barisLama->item->text ?? ''), 0, 160),
                'gold' => $barisLama->item->gold_sentiment ?? null,
                'segment' => $barisLama->item->segment ?? null,
                'before' => $barisLama->predicted_sentiment,
                'after' => $barisBaru->predicted_sentiment,
            ];

            if ($benarBaru) {
                $c++;
                if (count($membaik) < 20) {
                    $membaik[] = $catatan;
                }
            } else {
                $b++;
                if (count($memburuk) < 20) {
                    $memburuk[] = $catatan;
                }
            }
        }

        return compact('b', 'c', 'n', 'membaik', 'memburuk');
    }

    /**
     * Uji McNemar dua sisi.
     *
     * Untuk b+c kecil dipakai uji binomial eksak, bukan pendekatan khi-kuadrat:
     * pada jumlah pembalikan yang sedikit - keadaan yang normal ketika koreksi
     * active learning baru satu-dua putaran - pendekatan khi-kuadrat memberi
     * nilai p yang terlalu kecil, dan itu akan membuat perbaikan tampak
     * bermakna padahal belum tentu.
     */
    public function mcnemar(int $b, int $c, ?int $testSize = null): array
    {
        $n = $b + $c;

        if ($n === 0) {
            return [
                'b' => 0,
                'c' => 0,
                'n' => 0,
                'p_value' => 1.0,
                'method' => 'tidak ada pembalikan',
                'significant' => false,
                'power' => $this->powerNote($b, $testSize),
                'interpretation' => 'Tidak ada satu pun baris yang berubah benar/salah, jadi kedua model berperilaku sama pada test set ini.',
            ];
        }

        if ($n <= 1000) {
            $p = $this->binomialTwoSided($b, $n);
            $metode = 'binomial eksak';
        } else {
            $chi2 = (abs($b - $c) - 1) ** 2 / $n;
            $p = $this->chiSquareP1df($chi2);
            $metode = 'khi-kuadrat dengan koreksi kontinuitas';
        }

        $signifikan = $p < 0.05;
        $daya = $this->powerNote($b, $testSize);

        return [
            'b' => $b,
            'c' => $c,
            'n' => $n,
            'p_value' => round($p, 6),
            'method' => $metode,
            'significant' => $signifikan,
            'power' => $daya,
            'interpretation' => $this->tafsirMcnemar($b, $c, $p, $signifikan, $daya),
        ];
    }

    /**
     * Perbaikan bersih terkecil yang masih bisa dideteksi uji ini.
     *
     * Daya uji McNemar bergantung pada jumlah pembalikan (b+c), bukan pada
     * ukuran test set. Angka ini wajib diketahui SEBELUM studi kasus berjalan:
     * tanpa itu, koreksi bisa dikumpulkan berminggu-minggu lalu berakhir pada
     * "tidak signifikan" yang sebenarnya sudah bisa diramalkan. Pada b = 0,
     * misalnya, dibutuhkan minimal 6 baris membaik - perbaikan 1-2 poin akurasi
     * pada n = 300 tidak akan pernah signifikan, berapa pun rapinya
     * pekerjaannya.
     *
     * @return array{min_c: int|null, net: int|null, points: float|null, b: int}
     */
    public function powerNote(int $b, ?int $testSize = null): array
    {
        $minC = null;

        for ($c = $b + 1; $c <= $b + 500; $c++) {
            if ($this->binomialTwoSided(min($b, $c), $b + $c) < 0.05) {
                $minC = $c;
                break;
            }
        }

        return [
            'b' => $b,
            'min_c' => $minC,
            'net' => $minC !== null ? $minC - $b : null,
            'points' => ($minC !== null && $testSize) ? round(($minC - $b) / $testSize * 100, 2) : null,
        ];
    }

    private function tafsirMcnemar(int $b, int $c, float $p, bool $signifikan, array $daya): string
    {
        $arah = $c > $b ? 'membaik' : ($c < $b ? 'memburuk' : 'seimbang');
        $pTeks = 'p = '.($p < 0.001 ? '<0,001' : number_format($p, 3, ',', '.'));

        // Melaporkan p saja pada hasil yang tidak signifikan menyisakan
        // pertanyaan "seberapa besar perbaikan yang sebenarnya bisa terdeteksi".
        // Menjawabnya sendiri jauh lebih kuat daripada menunggu ditanya.
        $catatanDaya = '';

        if (! $signifikan && $daya['min_c'] !== null) {
            $catatanDaya = " Dengan {$b} baris yang memburuk, uji ini baru mendeteksi perubahan bila minimal "
                ."{$daya['min_c']} baris membaik (perbaikan bersih {$daya['net']} baris"
                .($daya['points'] !== null ? ", setara {$daya['points']} poin akurasi" : '').').';
        }

        if ($arah === 'seimbang') {
            return "Jumlah baris yang membaik dan memburuk sama banyak ({$c} berbanding {$b}); tidak ada bukti perubahan kinerja ({$pTeks}).".$catatanDaya;
        }

        if (! $signifikan) {
            return "Model {$arah} pada {$c} baris dan berlawanan pada {$b} baris, tetapi selisih sebesar ini masih mungkin muncul karena kebetulan ({$pTeks}). Belum cukup bukti untuk mengklaim perubahan kinerja.".$catatanDaya;
        }

        return "Perubahan bermakna secara statistik ({$pTeks}): {$c} baris berubah menjadi benar dan {$b} baris berubah menjadi salah.";
    }

    /**
     * Nilai p dua sisi untuk uji binomial dengan peluang 0,5.
     *
     * Dihitung iteratif dari suku pertama supaya tidak butuh faktorial: dengan
     * n beberapa ratus, faktorialnya jauh melewati batas float.
     */
    private function binomialTwoSided(int $k, int $n): float
    {
        $k = min($k, $n - $k);

        $suku = 2 ** (-$n);

        if ($suku === 0.0) {
            // n sangat besar; jatuh ke pendekatan normal.
            return $this->chiSquareP1df((abs(2 * $k - $n) - 1) ** 2 / $n);
        }

        $kumulatif = $suku;

        for ($i = 1; $i <= $k; $i++) {
            $suku *= ($n - $i + 1) / $i;
            $kumulatif += $suku;
        }

        return min(1.0, 2 * $kumulatif);
    }

    /**
     * Nilai p khi-kuadrat dengan 1 derajat bebas: p = erfc(sqrt(chi2/2)).
     */
    private function chiSquareP1df(float $chi2): float
    {
        if ($chi2 <= 0) {
            return 1.0;
        }

        return $this->erfc(sqrt($chi2 / 2));
    }

    /**
     * erfc dengan pendekatan rasional Numerical Recipes (galat < 1,2e-7).
     * PHP tidak menyediakannya tanpa ekstensi statistik.
     */
    private function erfc(float $x): float
    {
        $z = abs($x);
        $t = 1 / (1 + $z / 2);

        $hasil = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196
            + $t * (0.09678418 + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398
            + $t * (1.48851587 + $t * (-0.82215223 + $t * 0.17087277)))))))));

        return $x >= 0 ? $hasil : 2 - $hasil;
    }

    /**
     * Ambil dua run untuk dibandingkan berdasarkan id.
     */
    public function byIds(int $sebelumId, int $sesudahId): array
    {
        $sebelum = EvaluationRun::find($sebelumId);
        $sesudah = EvaluationRun::find($sesudahId);

        if (! $sebelum || ! $sesudah) {
            throw new RuntimeException('Run evaluasi tidak ditemukan.');
        }

        return $this->compare($sebelum, $sesudah);
    }
}
