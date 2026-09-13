<?php

namespace App\Services\Evaluation;

/**
 * Metrik klasifikasi untuk hold-out test.
 *
 * Dipisahkan dari ModelEvaluationService yang lama karena keduanya menjawab
 * pertanyaan yang berbeda:
 *
 * - ModelEvaluationService menghitung metrik atas data koreksi - data yang
 *   ikut dilatihkan. Angkanya berguna untuk memantau proses koreksi, tetapi
 *   tidak boleh dikutip sebagai kinerja model.
 * - Kelas ini menghitung metrik atas golden dataset yang tidak pernah
 *   dilatihkan, lengkap dengan selang kepercayaan dan pembanding dasar.
 *
 * Yang ditambahkan dibanding perhitungan lama, dan alasannya:
 *
 * - Macro-F1, bukan hanya weighted. Pada korpus komentar yang timpang,
 *   weighted-F1 didominasi kelas terbanyak sehingga model yang gagal total
 *   pada kelas minoritas tetap terlihat baik.
 * - Selang kepercayaan Wilson untuk akurasi. Dengan n beberapa ratus, selisih
 *   dua angka bisa saja hanya derau; naskah harus menyebut marginnya.
 * - Akurasi tebak-kelas-mayoritas sebagai lantai pembanding. Model yang tidak
 *   mengunggulinya belum membuktikan apa pun.
 * - Cohen kappa: kesepakatan di atas peluang kebetulan.
 */
class ClassificationMetrics
{
    public const LABELS = ['positive', 'neutral', 'negative'];

    /** z untuk selang kepercayaan 95%. */
    private const Z95 = 1.959964;

    /**
     * @param  array<int, array{gold: string, predicted: ?string}>  $pairs
     */
    public function sentiment(array $pairs, int $bootstrapRounds = 1000, int $seed = 42): array
    {
        $labels = self::LABELS;

        $confusion = [];
        foreach ($labels as $a) {
            foreach ($labels as $b) {
                $confusion[$a][$b] = 0;
            }
        }

        // Baris yang tidak bisa dinilai dicatat terpisah, tidak dibuang diam-diam:
        // model yang gagal menjawab 30% korpus tidak boleh terlihat sama dengan
        // model yang menjawab semuanya.
        $tidakTerbandingkan = 0;
        $benar = 0;
        $dibandingkan = 0;
        $emasList = [];
        $prediksiList = [];

        foreach ($pairs as $pair) {
            $emas = strtolower((string) ($pair['gold'] ?? ''));
            $prediksi = strtolower((string) ($pair['predicted'] ?? ''));

            if (! in_array($emas, $labels, true) || ! in_array($prediksi, $labels, true)) {
                $tidakTerbandingkan++;

                continue;
            }

            $confusion[$emas][$prediksi]++;
            $dibandingkan++;
            $emasList[] = $emas;
            $prediksiList[] = $prediksi;

            if ($emas === $prediksi) {
                $benar++;
            }
        }

        if ($dibandingkan === 0) {
            return [
                'available' => false,
                'message' => 'Tidak ada baris yang bisa dibandingkan.',
                'unscorable' => $tidakTerbandingkan,
            ];
        }

        $perKelas = $this->perClass($confusion, $labels);
        $rerata = $this->averages($perKelas, $dibandingkan);
        $akurasi = $benar / $dibandingkan;

        return [
            'available' => true,
            'n' => $dibandingkan,
            'unscorable' => $tidakTerbandingkan,
            'accuracy' => $this->persen($akurasi),
            'accuracy_ci' => $this->wilson($benar, $dibandingkan),
            'macro_precision' => $this->persen($rerata['macro']['precision']),
            'macro_recall' => $this->persen($rerata['macro']['recall']),
            'macro_f1' => $this->persen($rerata['macro']['f1']),
            'macro_f1_ci' => $this->bootstrapMacroF1($emasList, $prediksiList, $labels, $bootstrapRounds, $seed),
            'weighted_precision' => $this->persen($rerata['weighted']['precision']),
            'weighted_recall' => $this->persen($rerata['weighted']['recall']),
            'weighted_f1' => $this->persen($rerata['weighted']['f1']),
            'per_class' => $this->persenPerKelas($perKelas),
            'confusion_matrix' => $confusion,
            'labels' => $labels,
            'cohen_kappa' => round($this->kappa($confusion, $labels, $dibandingkan), 4),
            'majority_baseline' => $this->majorityBaseline($confusion, $labels, $dibandingkan),
            'correct' => $benar,
        ];
    }

    /**
     * Evaluasi aspek sebagai klasifikasi multi-label.
     *
     * Micro dan macro dilaporkan keduanya: micro didominasi aspek yang sering
     * muncul, macro memperlakukan tiap aspek setara. Keduanya bersama-sama
     * memperlihatkan apakah model hanya pandai pada aspek populer.
     *
     * @param  array<int, array{gold: array, predicted: array}>  $pairs
     */
    public function aspects(array $pairs): array
    {
        $ketat = $this->aspectScores($pairs, false);
        $parsial = $this->aspectScores($pairs, true);

        if (! ($ketat['available'] ?? false)) {
            return $ketat;
        }

        // Dua angka dilaporkan bersama, dan itu disengaja.
        //
        // Pencocokan KETAT menuntut nama aspek sama persis setelah dinormalkan.
        // Ia menghukum perbedaan granularitas yang bukan kesalahan model: label
        // emas `dewan wakil rakyat` versus prediksi `dewan`+`rakyat` dihitung
        // satu false negative DAN dua false positive, padahal konsepnya justru
        // ditemukan. Terukur pada golden dataset 300 baris: 73 label emas
        // berupa frasa, dan micro-F1 naik 45,09 -> 51,34 begitu granularitasnya
        // dimaafkan.
        //
        // Pencocokan PARSIAL menganggap satu label emas ditemukan bila ada
        // kata-dasar yang sama dengan salah satu prediksi. Ini praktik lazim
        // pada ABSA untuk aspek berbentuk frasa.
        //
        // Angka ketat TETAP dilaporkan sebagai batas bawah, supaya pelonggaran
        // ini tidak menyembunyikan apa pun.
        $ketat['matching'] = 'ketat (nama aspek sama persis setelah dinormalkan)';
        $ketat['partial'] = [
            'matching' => 'parsial (satu label emas dianggap ketemu bila ada kata-dasar yang sama)',
            'micro_precision' => $parsial['micro_precision'],
            'micro_recall' => $parsial['micro_recall'],
            'micro_f1' => $parsial['micro_f1'],
            'macro_f1' => $parsial['macro_f1'],
            'exact_match' => $parsial['exact_match'],
            'tp' => $parsial['tp'],
            'fp' => $parsial['fp'],
            'fn' => $parsial['fn'],
        ];

        return $ketat;
    }

    /**
     * @param  bool  $parsial  Cocokkan lewat irisan kata-dasar, bukan nama utuh.
     */
    private function aspectScores(array $pairs, bool $parsial): array
    {
        $tp = 0;
        $fp = 0;
        $fn = 0;
        $exact = 0;
        $hamming = 0;
        $dibandingkan = 0;
        $perAspek = [];

        foreach ($pairs as $pair) {
            $emas = $this->normalizeSet($pair['gold'] ?? []);
            $prediksi = $this->normalizeSet($pair['predicted'] ?? []);

            $emasSet = array_fill_keys($emas, true);
            $prediksiSet = array_fill_keys($prediksi, true);

            if ($parsial) {
                [$irisan, $lebih, $kurang] = $this->cocokParsial($emas, $prediksi);
            } else {
                $irisan = array_intersect_key($prediksiSet, $emasSet);
                $lebih = array_diff_key($prediksiSet, $emasSet);
                $kurang = array_diff_key($emasSet, $prediksiSet);
            }

            $tp += count($irisan);
            $fp += count($lebih);
            $fn += count($kurang);
            $hamming += count($lebih) + count($kurang);

            foreach (array_keys($irisan) as $aspek) {
                $perAspek[$aspek]['tp'] = ($perAspek[$aspek]['tp'] ?? 0) + 1;
            }
            foreach (array_keys($lebih) as $aspek) {
                $perAspek[$aspek]['fp'] = ($perAspek[$aspek]['fp'] ?? 0) + 1;
            }
            foreach (array_keys($kurang) as $aspek) {
                $perAspek[$aspek]['fn'] = ($perAspek[$aspek]['fn'] ?? 0) + 1;
            }

            if ($emas === $prediksi) {
                $exact++;
            }

            $dibandingkan++;
        }

        if ($dibandingkan === 0) {
            return [
                'available' => false,
                'message' => 'Golden dataset ini tidak memuat label aspek.',
            ];
        }

        $micro = $this->prf($tp, $fp, $fn);

        $rincian = [];
        $makroP = 0.0;
        $makroR = 0.0;
        $makroF = 0.0;

        foreach ($perAspek as $aspek => $hitung) {
            $skor = $this->prf($hitung['tp'] ?? 0, $hitung['fp'] ?? 0, $hitung['fn'] ?? 0);
            $rincian[$aspek] = [
                'precision' => $this->persen($skor['precision']),
                'recall' => $this->persen($skor['recall']),
                'f1' => $this->persen($skor['f1']),
                'support' => ($hitung['tp'] ?? 0) + ($hitung['fn'] ?? 0),
            ];
            $makroP += $skor['precision'];
            $makroR += $skor['recall'];
            $makroF += $skor['f1'];
        }

        $jumlahAspek = max(count($rincian), 1);
        ksort($rincian);

        return [
            'available' => true,
            'n' => $dibandingkan,
            'exact_match' => $this->persen($exact / $dibandingkan),
            'hamming_loss' => round($hamming / $dibandingkan, 4),
            'micro_precision' => $this->persen($micro['precision']),
            'micro_recall' => $this->persen($micro['recall']),
            'micro_f1' => $this->persen($micro['f1']),
            'macro_precision' => $this->persen($makroP / $jumlahAspek),
            'macro_recall' => $this->persen($makroR / $jumlahAspek),
            'macro_f1' => $this->persen($makroF / $jumlahAspek),
            // Macro atas aspek yang cukup sering muncul.
            //
            // Macro biasa merata-ratakan SETIAP jenis aspek dengan bobot sama,
            // termasuk yang hanya muncul sekali. Terukur pada golden dataset
            // studi kasus: 204 jenis aspek dinilai, tetapi hanya 63 muncul dua
            // kali atau lebih - jadi angkanya didominasi 141 aspek yang skornya
            // cuma bisa 0 atau 1 dan tidak bermakna statistik.
            //
            // Keduanya dilaporkan; yang bersyarat support TIDAK menggantikan
            // yang penuh, hanya melengkapinya.
            'macro_frequent' => $this->macroBersyaratSupport($perAspek, 2),
            'per_aspect' => $rincian,
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
        ];
    }

    /**
     * Tabel reliabilitas: apakah keyakinan model benar-benar menaksir peluang
     * benarnya.
     *
     * Inilah yang membuat ambang tinjauan bisa dipertanggungjawabkan. Ambang
     * 0,90 berarti "tinjau bila perkiraan salahnya di atas 10%" - klaim itu
     * hanya sah bila keyakinan model memang terkalibrasi. Tabel ini mengujinya
     * pada golden dataset sendiri, bukan pada set uji SmSA milik layanan.
     *
     * Yang dilaporkan per pita: jumlah baris, porsi, akurasi nyata, rerata
     * keyakinan, dan selisih keduanya. Selisih positif berarti model terlalu
     * rendah hati; negatif berarti terlalu percaya diri - dan pita yang terlalu
     * percaya diri persis alasan mengapa baris di sana perlu dilihat manusia.
     *
     * ECE dihitung sebagai rerata terbobot |akurasi - keyakinan| antar pita
     * (Naeini dkk., 2015). Nilainya bergantung pada pembagian pita, jadi
     * batasnya ikut dilaporkan.
     *
     * @param  array<int, array{confidence: ?float, correct: bool}>  $baris
     * @param  array<int, float>  $batas  ambang pemisah pita, menaik
     */
    public function reliability(array $baris, array $batas): ?array
    {
        $baris = array_values(array_filter(
            $baris,
            fn ($b) => isset($b['confidence']) && is_numeric($b['confidence'])
        ));

        if (empty($baris)) {
            return null;
        }

        sort($batas);
        $tepi = array_values(array_unique(array_merge([0.0], $batas, [1.0])));

        $pita = [];

        for ($i = 0; $i < count($tepi) - 1; $i++) {
            $bawah = $tepi[$i];
            $atas = $tepi[$i + 1];
            $terakhir = $i === count($tepi) - 2;

            $anggota = array_values(array_filter($baris, function ($b) use ($bawah, $atas, $terakhir) {
                $c = (float) $b['confidence'];

                return $c >= $bawah && ($terakhir ? $c <= $atas : $c < $atas);
            }));

            $n = count($anggota);

            if ($n === 0) {
                $pita[] = [
                    'range' => $this->rentang($bawah, $atas),
                    'lower' => $bawah,
                    'n' => 0,
                    'share' => 0.0,
                    'accuracy' => null,
                    'confidence' => null,
                    'gap' => null,
                ];

                continue;
            }

            $benar = count(array_filter($anggota, fn ($b) => ! empty($b['correct'])));
            $akurasi = $benar / $n;
            $keyakinan = array_sum(array_map(fn ($b) => (float) $b['confidence'], $anggota)) / $n;

            $pita[] = [
                'range' => $this->rentang($bawah, $atas),
                'lower' => $bawah,
                'n' => $n,
                'share' => round($n / count($baris) * 100, 1),
                'accuracy' => $this->persen($akurasi),
                'confidence' => $this->persen($keyakinan),
                'gap' => round(($akurasi - $keyakinan) * 100, 2),
            ];
        }

        $ece = 0.0;
        foreach ($pita as $p) {
            if ($p['n'] > 0) {
                $ece += ($p['n'] / count($baris)) * abs($p['accuracy'] - $p['confidence']) / 100;
            }
        }

        return [
            'available' => true,
            'n' => count($baris),
            'bins' => $pita,
            'ece' => round($ece, 4),
            'bounds' => $batas,
        ];
    }

    private function rentang(float $bawah, float $atas): string
    {
        return number_format($bawah, 2).'-'.number_format($atas, 2);
    }

    /**
     * Selang kepercayaan Wilson untuk proporsi.
     *
     * Dipilih daripada selang normal (Wald) karena Wald melebar melewati 0/1
     * dan runtuh ketika proporsinya mendekati ujung - persis keadaan akurasi
     * tinggi pada sampel beberapa ratus baris.
     */
    public function wilson(int $sukses, int $total, float $z = self::Z95): array
    {
        if ($total === 0) {
            return ['low' => 0.0, 'high' => 0.0, 'margin' => 0.0];
        }

        $p = $sukses / $total;
        $z2 = $z * $z;
        $penyebut = 1 + $z2 / $total;
        $pusat = ($p + $z2 / (2 * $total)) / $penyebut;
        $sebar = ($z / $penyebut) * sqrt($p * (1 - $p) / $total + $z2 / (4 * $total * $total));

        return [
            'low' => $this->persen(max(0.0, $pusat - $sebar)),
            'high' => $this->persen(min(1.0, $pusat + $sebar)),
            'margin' => $this->persen($sebar),
        ];
    }

    /**
     * Selang kepercayaan macro-F1 lewat bootstrap persentil.
     *
     * Macro-F1 bukan rerata nilai per baris, jadi rumus tertutup seperti Wilson
     * tidak berlaku. Bootstrap disemai tetap supaya angka yang ditulis di naskah
     * bisa dihitung ulang dan hasilnya sama persis.
     */
    private function bootstrapMacroF1(array $emas, array $prediksi, array $labels, int $rounds, int $seed): array
    {
        $n = count($emas);

        if ($n === 0 || $rounds <= 0) {
            return ['low' => 0.0, 'high' => 0.0, 'rounds' => 0, 'seed' => $seed];
        }

        mt_srand($seed);
        $skor = [];

        for ($r = 0; $r < $rounds; $r++) {
            $confusion = [];
            foreach ($labels as $a) {
                foreach ($labels as $b) {
                    $confusion[$a][$b] = 0;
                }
            }

            for ($i = 0; $i < $n; $i++) {
                $pilih = mt_rand(0, $n - 1);
                $confusion[$emas[$pilih]][$prediksi[$pilih]]++;
            }

            $perKelas = $this->perClass($confusion, $labels);
            $skor[] = $this->averages($perKelas, $n)['macro']['f1'];
        }

        sort($skor);
        mt_srand();

        return [
            'low' => $this->persen($skor[(int) floor(0.025 * ($rounds - 1))]),
            'high' => $this->persen($skor[(int) ceil(0.975 * ($rounds - 1))]),
            'rounds' => $rounds,
            'seed' => $seed,
        ];
    }

    private function perClass(array $confusion, array $labels): array
    {
        $hasil = [];

        foreach ($labels as $label) {
            $tp = $confusion[$label][$label];
            $fp = 0;
            $fn = 0;

            foreach ($labels as $lain) {
                if ($lain !== $label) {
                    $fp += $confusion[$lain][$label];
                    $fn += $confusion[$label][$lain];
                }
            }

            $skor = $this->prf($tp, $fp, $fn);

            $hasil[$label] = [
                'precision' => $skor['precision'],
                'recall' => $skor['recall'],
                'f1' => $skor['f1'],
                'support' => array_sum($confusion[$label]),
                'tp' => $tp,
                'fp' => $fp,
                'fn' => $fn,
            ];
        }

        return $hasil;
    }

    private function persenPerKelas(array $perKelas): array
    {
        foreach ($perKelas as $label => $skor) {
            $perKelas[$label]['precision'] = $this->persen($skor['precision']);
            $perKelas[$label]['recall'] = $this->persen($skor['recall']);
            $perKelas[$label]['f1'] = $this->persen($skor['f1']);
        }

        return $perKelas;
    }

    private function averages(array $perKelas, int $total): array
    {
        $macro = ['precision' => 0.0, 'recall' => 0.0, 'f1' => 0.0];
        $weighted = ['precision' => 0.0, 'recall' => 0.0, 'f1' => 0.0];
        $jumlahKelas = max(count($perKelas), 1);

        foreach ($perKelas as $skor) {
            foreach (['precision', 'recall', 'f1'] as $kunci) {
                $macro[$kunci] += $skor[$kunci] / $jumlahKelas;
                $weighted[$kunci] += $skor[$kunci] * ($total > 0 ? $skor['support'] / $total : 0);
            }
        }

        return ['macro' => $macro, 'weighted' => $weighted];
    }

    private function prf(int $tp, int $fp, int $fn): array
    {
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return compact('precision', 'recall', 'f1');
    }

    /**
     * Cohen kappa antara label emas dan prediksi model.
     */
    private function kappa(array $confusion, array $labels, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        $po = 0;
        $pe = 0.0;

        foreach ($labels as $label) {
            $po += $confusion[$label][$label];

            $barisEmas = array_sum($confusion[$label]);
            $kolomPrediksi = 0;
            foreach ($labels as $lain) {
                $kolomPrediksi += $confusion[$lain][$label];
            }

            $pe += ($barisEmas / $total) * ($kolomPrediksi / $total);
        }

        $po = $po / $total;

        return $pe < 1.0 ? ($po - $pe) / (1 - $pe) : 0.0;
    }

    /**
     * Akurasi bila seluruh baris ditebak dengan kelas terbanyak.
     * Lantai pembanding: model yang tidak melewatinya belum membuktikan apa pun.
     */
    private function majorityBaseline(array $confusion, array $labels, int $total): array
    {
        $terbanyak = null;
        $jumlah = 0;

        foreach ($labels as $label) {
            $support = array_sum($confusion[$label]);
            if ($support > $jumlah) {
                $jumlah = $support;
                $terbanyak = $label;
            }
        }

        return [
            'label' => $terbanyak,
            'accuracy' => $this->persen($total > 0 ? $jumlah / $total : 0),
        ];
    }

    /**
     * Macro P/R/F1 dibatasi pada aspek dengan support minimal.
     *
     * `support` = TP + FN, yaitu berapa kali aspek itu benar-benar ada pada
     * label emas. Aspek yang hanya muncul sekali membuat macro bergerak liar
     * tanpa menambah informasi.
     *
     * @param  array<string, array<string,int>>  $perAspek
     * @return array<string, float|int>
     */
    private function macroBersyaratSupport(array $perAspek, int $minSupport): array
    {
        $p = 0.0;
        $r = 0.0;
        $f = 0.0;
        $n = 0;

        foreach ($perAspek as $hitung) {
            $tp = $hitung['tp'] ?? 0;
            $fn = $hitung['fn'] ?? 0;

            if (($tp + $fn) < $minSupport) {
                continue;
            }

            $skor = $this->prf($tp, $hitung['fp'] ?? 0, $fn);
            $p += $skor['precision'];
            $r += $skor['recall'];
            $f += $skor['f1'];
            $n++;
        }

        if ($n === 0) {
            return ['min_support' => $minSupport, 'aspects' => 0,
                'macro_precision' => 0.0, 'macro_recall' => 0.0, 'macro_f1' => 0.0];
        }

        return [
            'min_support' => $minSupport,
            'aspects' => $n,
            'macro_precision' => $this->persen($p / $n),
            'macro_recall' => $this->persen($r / $n),
            'macro_f1' => $this->persen($f / $n),
        ];
    }

    /**
     * Cocokkan lewat irisan kata, bukan nama aspek utuh.
     *
     * Sebuah label emas dianggap DITEMUKAN bila salah satu katanya muncul pada
     * salah satu prediksi, dan sebaliknya sebuah prediksi baru dihitung sebagai
     * false positive bila ia tidak menyentuh satu pun label emas. Ini yang
     * memaafkan perbedaan granularitas - `dewan wakil rakyat` versus `dewan` -
     * tanpa memaafkan aspek yang memang salah.
     *
     * Kata sepanjang <= 2 huruf diabaikan supaya `ri`, `pp`, dan sejenisnya
     * tidak membuat dua aspek yang tak berhubungan tampak cocok.
     *
     * @return array{0: array<string,bool>, 1: array<string,bool>, 2: array<string,bool>}
     */
    private function cocokParsial(array $emas, array $prediksi): array
    {
        $kataEmas = [];
        foreach ($emas as $g) {
            $kataEmas[$g] = $this->kataKunci($g);
        }

        $kataPrediksi = [];
        foreach ($prediksi as $p) {
            $kataPrediksi[$p] = $this->kataKunci($p);
        }

        $semuaPrediksi = array_merge(...array_values($kataPrediksi) ?: [[]]);
        $semuaEmas = array_merge(...array_values($kataEmas) ?: [[]]);

        $irisan = [];
        $kurang = [];
        foreach ($kataEmas as $g => $kata) {
            if (array_intersect($kata, $semuaPrediksi)) {
                $irisan[$g] = true;
            } else {
                $kurang[$g] = true;
            }
        }

        $lebih = [];
        foreach ($kataPrediksi as $p => $kata) {
            if (! array_intersect($kata, $semuaEmas)) {
                $lebih[$p] = true;
            }
        }

        return [$irisan, $lebih, $kurang];
    }

    /**
     * Kata bermakna pada sebuah nama aspek, untuk pencocokan parsial.
     *
     * @return array<int, string>
     */
    private function kataKunci(string $aspek): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', $aspek) ?: [],
            fn ($kata) => mb_strlen($kata) > 2
        ));
    }

    private function normalizeSet(array|string|null $aspects): array
    {
        if (is_string($aspects)) {
            $decoded = json_decode($aspects, true);
            $aspects = is_array($decoded) ? $decoded : explode(',', $aspects);
        }

        if (! is_array($aspects)) {
            return [];
        }

        $bersih = array_filter(array_map(
            fn ($aspek) => mb_strtolower(trim((string) $aspek)),
            $aspects
        ), fn ($aspek) => $aspek !== '');

        $bersih = array_values(array_unique($bersih));
        sort($bersih);

        return $bersih;
    }

    private function persen(float $nilai): float
    {
        return round($nilai * 100, 2);
    }
}
