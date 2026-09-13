<?php

namespace App\Console\Commands;

use App\Models\EvaluationDataset;
use App\Services\Evaluation\EvaluationComparisonService;
use App\Services\Evaluation\GoldenDatasetService;
use Illuminate\Console\Command;

/**
 * Menyusun laporan evaluasi siap salin ke naskah.
 *
 * Sengaja berupa berkas markdown, bukan hanya tampilan di layar: tabel di
 * naskah harus berasal dari angka yang tersimpan, bukan diketik ulang dari
 * tangkapan layar.
 */
class EvaluationReport extends Command
{
    protected $signature = 'evaluation:report
        {--dataset= : ID golden dataset; bawaannya semua dataset beku}
        {--out= : Tulis ke berkas markdown}';

    protected $description = 'Susun laporan evaluasi hold-out untuk naskah';

    public function handle(EvaluationComparisonService $banding, GoldenDatasetService $golden): int
    {
        $datasets = $this->option('dataset')
            ? EvaluationDataset::where('id', $this->option('dataset'))->get()
            : EvaluationDataset::whereNotNull('frozen_at')->orderBy('id')->get();

        if ($datasets->isEmpty()) {
            $this->error('Tidak ada golden dataset yang bisa dilaporkan.');

            return self::FAILURE;
        }

        $baris = [];
        $baris[] = '# Laporan Evaluasi Hold-out';
        $baris[] = '';
        $baris[] = 'Dibangkitkan '.now()->format('d F Y H:i').' oleh `php artisan evaluation:report`.';
        $baris[] = '';

        foreach ($datasets as $dataset) {
            $baris = array_merge($baris, $this->bagianDataset($dataset, $banding, $golden));
        }

        $isi = implode("\n", $baris);

        if ($out = $this->option('out')) {
            file_put_contents($out, $isi);
            $this->info('Laporan ditulis ke '.$out);

            return self::SUCCESS;
        }

        $this->line($isi);

        return self::SUCCESS;
    }

    private function bagianDataset(EvaluationDataset $dataset, EvaluationComparisonService $banding, GoldenDatasetService $golden): array
    {
        $b = [];
        $b[] = '## '.$dataset->name;
        $b[] = '';
        $b[] = '- Jumlah baris: **'.$dataset->item_count.'**';
        $b[] = '- Dibekukan: '.($dataset->frozen_at?->format('d F Y H:i') ?? '**belum dibekukan - angka di bawah tidak sebanding antar iterasi**');

        $dist = $dataset->label_distribution ?? [];
        $sebaran = [];
        foreach (GoldenDatasetService::LABEL_SAH as $label) {
            $sebaran[] = $label.' '.($dist[$label] ?? 0);
        }
        $b[] = '- Distribusi label: '.implode(', ', $sebaran);

        if ($reliabilitas = $dataset->annotation) {
            $b[] = sprintf(
                '- **Kappa antar-anotator (n=%d): %.3f** (%s), kesepakatan mentah %.1f%%. '
                .'Ini batas atas kinerja yang masuk akal: model dengan kappa mendekati angka ini sudah berada '
                .'di batas kebisingan labelnya, dan selisih di atas itu tidak bermakna.',
                $reliabilitas['double_annotated'],
                $reliabilitas['cohen_kappa'],
                $reliabilitas['label'],
                $reliabilitas['agreement']
            );
        } else {
            $b[] = '- Kappa antar-anotator: **tidak ada anotator kedua**, jadi batas atas kinerja model tidak diketahui';
        }

        if ($dataset->content_hash) {
            $b[] = '- Sidik jari isi dataset: `'.substr($dataset->content_hash, 0, 16).'...` '
                .'(SHA-256 atas seluruh sidik jari baris yang diurutkan; membuktikan tiap iterasi mengukur baris yang sama)';
        }

        if ($rencana = $dataset->sampling_plan) {
            $ditolak = array_sum($rencana['rejected'] ?? []);
            $kandidatAwal = $rencana['candidates'] + $ditolak;
            $porsi = $kandidatAwal > 0 ? round($ditolak / $kandidatAwal * 100, 1) : 0;

            $b[] = '- Populasi yang diukur: **komentar unik sepanjang minimal '.$rencana['min_length'].' karakter**, '
                .'bukan seluruh korpus. '.$ditolak.' dari '.$kandidatAwal.' baris ('.$porsi.'%) tersaring '
                .'(tidak bisa dinilai '.($rencana['rejected']['tidak_dinilai'] ?? 0)
                .', terlalu pendek '.($rencana['rejected']['terlalu_pendek'] ?? 0).', duplikat '
                .($rencana['rejected']['duplikat'] ?? 0).', sudah dikoreksi '
                .($rencana['rejected']['sudah_dikoreksi'] ?? 0).').';
            $b[] = '- Arah bias penyaringan itu **ke atas**: komentar pendek justru tempat model paling lemah karena '
                .'konteksnya sedikit, jadi akurasi hold-out sedikit melebih-lebihkan kinerja pada korpus utuh.';
            $b[] = '- Pengambilan sampel: '.$rencana['method'].', semai '.$rencana['seed']
                .', stratifikasi '.($rencana['stratify_by'] ?? 'per analisis').'.';
        }

        $bocor = $golden->leakageReport($dataset);
        $b[] = '- Kebocoran ke data latih: '.($bocor['overlap'] === 0
            ? '**nihil**'
            : '**'.$bocor['overlap'].' baris** bertabrakan ('.$bocor['corrected_overlap'].' sudah dikoreksi); baris tersebut disingkirkan otomatis dari pelatihan');
        $b[] = '';

        $kurva = $banding->learningCurve($dataset);

        if (empty($kurva)) {
            $b[] = '_Belum ada evaluasi yang dijalankan pada dataset ini._';
            $b[] = '';

            return $b;
        }

        $b[] = '### Hasil per iterasi';
        $b[] = '';
        $b[] = '| Iterasi | Akurasi | 95% CI | Macro-F1 | 95% CI | Weighted-F1 | Kappa model | Baseline |';
        $b[] = '|---|---|---|---|---|---|---|---|';

        foreach ($kurva as $titik) {
            $b[] = sprintf(
                '| %s | %s%% | %s-%s | %s%% | %s-%s | %s%% | %s | %s%% |',
                $titik['label'],
                $titik['accuracy'],
                $titik['accuracy_ci']['low'] ?? '-',
                $titik['accuracy_ci']['high'] ?? '-',
                $titik['macro_f1'],
                $titik['macro_f1_ci']['low'] ?? '-',
                $titik['macro_f1_ci']['high'] ?? '-',
                $titik['weighted_f1'],
                $titik['cohen_kappa'],
                $titik['majority_baseline']
            );
        }

        $b[] = '';

        if (count($kurva) >= 2) {
            $awal = $dataset->runs()->completed()->orderBy('iteration')->orderBy('id')->first();
            $akhir = $dataset->runs()->completed()->orderByDesc('iteration')->orderByDesc('id')->first();
            $hasil = $banding->compare($awal, $akhir);

            if ($hasil['valid'] ?? false) {
                $m = $hasil['mcnemar'];
                $d = $hasil['deltas'];

                $b[] = '### Uji signifikansi iterasi awal vs akhir';
                $b[] = '';
                $b[] = '**Uji utama, ditetapkan di muka: iterasi pertama lawan iterasi terakhir.** Perbandingan '
                    .'antar-iterasi yang lain (mis. dari `--compare-last`) bersifat deskriptif dan tidak diklaim '
                    .'signifikansinya - menguji setiap pasangan iterasi adalah pengujian berganda, dan dengan empat '
                    .'uji pada alfa 0,05 peluang setidaknya satu "signifikan" palsu naik ke sekitar 19%.';
                $b[] = '';
                $b[] = 'Uji McNemar ('.$m['method'].') atas '.$m['n'].' baris yang berubah status benar/salah:';
                $b[] = '';
                $b[] = '- Berubah menjadi benar (c): **'.$m['c'].'**';
                $b[] = '- Berubah menjadi salah (b): **'.$m['b'].'**';
                $b[] = '- Nilai p: **'.$m['p_value'].'**';
                $b[] = '- Kesimpulan: '.$m['interpretation'];

                if (($m['power']['min_c'] ?? null) !== null) {
                    $b[] = '- Daya uji: dengan '.$m['b'].' baris memburuk, perubahan baru terdeteksi bila minimal '
                        .$m['power']['min_c'].' baris membaik (bersih '.$m['power']['net'].' baris'
                        .($m['power']['points'] !== null ? ', setara '.$m['power']['points'].' poin akurasi' : '').').';
                }
                $b[] = '';
                $b[] = 'Selisih akurasi '.$this->tanda($d['accuracy']).', macro-F1 '.$this->tanda($d['macro_f1']).'.';
                $b[] = '';

                if ($d['coherence'] !== null) {
                    $b[] = 'Koherensi topik bergeser '.($d['coherence'] > 0 ? '+' : '').number_format($d['coherence'], 4).
                        '. Angka ini berperan sebagai kontrol: model topik tidak ikut dilatih ulang oleh active '.
                        'learning, jadi pergeseran yang besar justru menandakan korpus atau parameternya yang '.
                        'berubah, bukan pelatihan ulangnya.';
                    $b[] = '';
                }
            }
        } else {
            $b[] = '_Baru satu iterasi. Uji signifikansi memerlukan minimal dua run pada dataset yang sama._';
            $b[] = '';
        }

        $terakhir = $dataset->runs()->completed()->latest('id')->first();

        if ($terakhir) {
            $b = array_merge($b, $this->bagianRinci($terakhir));
        }

        return $b;
    }

    private function bagianRinci($run): array
    {
        $s = $run->metrics['sentiment'] ?? [];
        $b = [];

        if (! ($s['available'] ?? false)) {
            return $b;
        }

        $b[] = '### Rincian run terakhir ('.$run->judul().')';
        $b[] = '';
        $b[] = '| Kelas | Presisi | Recall | F1 | Support |';
        $b[] = '|---|---|---|---|---|';

        foreach ($s['per_class'] as $label => $skor) {
            $b[] = sprintf('| %s | %s%% | %s%% | %s%% | %d |', $label, $skor['precision'], $skor['recall'], $skor['f1'], $skor['support']);
        }

        $b[] = '';
        $b[] = '#### Matriks kebingungan (baris = label emas, kolom = prediksi)';
        $b[] = '';
        $b[] = '| | '.implode(' | ', $s['labels']).' |';
        $b[] = '|---|'.str_repeat('---|', count($s['labels']));

        foreach ($s['labels'] as $emas) {
            $sel = [];
            foreach ($s['labels'] as $prediksi) {
                $sel[] = $s['confusion_matrix'][$emas][$prediksi];
            }
            $b[] = '| **'.$emas.'** | '.implode(' | ', $sel).' |';
        }

        $b[] = '';

        $rel = $run->metrics['reliability'] ?? null;

        if ($rel && ($rel['available'] ?? false)) {
            $b[] = '#### Reliabilitas keyakinan';
            $b[] = '';
            $b[] = '| Rentang keyakinan | n | Porsi | Akurasi nyata | Rerata keyakinan | Selisih |';
            $b[] = '|---|---|---|---|---|---|';

            foreach ($rel['bins'] as $pita) {
                $b[] = sprintf(
                    '| %s | %d | %s%% | %s | %s | %s |',
                    $pita['range'],
                    $pita['n'],
                    $pita['share'],
                    $pita['accuracy'] === null ? '-' : $pita['accuracy'].'%',
                    $pita['confidence'] === null ? '-' : $pita['confidence'].'%',
                    $pita['gap'] === null ? '-' : ($pita['gap'] > 0 ? '+' : '').$pita['gap'],
                );
            }

            $b[] = '';
            $b[] = '_ECE '.$rel['ece'].' atas '.$rel['n'].' baris. Tabel ini yang membenarkan ambang '
                .'tinjauan: ambang menyatakan "tinjau bila perkiraan salahnya di atas sekian", dan klaim itu '
                .'hanya sah bila keyakinan model memang menaksir peluang benarnya. Selisih negatif berarti '
                .'model terlalu percaya diri di pita tersebut._';
            $b[] = '';
        }

        if ($aspek = ($run->metrics['aspect'] ?? null)) {
            $b[] = '#### Aspek (multi-label)';
            $b[] = '';

            if ($aspek['available'] ?? false) {
                // Empat angka, bukan satu. Melaporkan yang ketat saja
                // menyembunyikan dua pertanyaan yang pasti ditanyakan penguji:
                // seberapa besar hukuman granularitas frasa, dan seberapa besar
                // macro ditarik turun aspek yang cuma muncul sekali.
                $parsial = $aspek['partial'] ?? [];
                $sering = $aspek['macro_frequent'] ?? [];

                $b[] = '| Pencocokan | Micro-F1 | Macro-F1 |';
                $b[] = '|---|---|---|';
                $b[] = '| Ketat (nama sama persis) | **'.$aspek['micro_f1'].'%** | **'.$aspek['macro_f1'].'%** |';
                $b[] = '| Parsial (irisan kata-dasar) | '
                    .(isset($parsial['micro_f1']) ? '**'.$parsial['micro_f1'].'%**' : '-').' | '
                    .(isset($parsial['macro_f1']) ? $parsial['macro_f1'].'%' : '-').' |';
                $b[] = '| Ketat, aspek support &ge; '.($sering['min_support'] ?? 2)
                    .' ('.($sering['aspects'] ?? 0).' jenis) | - | '
                    .(isset($sering['macro_f1']) ? '**'.$sering['macro_f1'].'%**' : '-').' |';
                $b[] = '';
                $b[] = '- Exact match '.$aspek['exact_match'].'%, Hamming loss '.$aspek['hamming_loss']
                    .', TP/FP/FN '.$aspek['tp'].'/'.$aspek['fp'].'/'.$aspek['fn'];

                if (isset($sering['aspects']) && isset($aspek['per_aspect'])) {
                    $jenis = count($aspek['per_aspect']);
                    $jarang = $jenis - (int) $sering['aspects'];

                    if ($jarang > 0) {
                        $b[] = '- Macro penuh merata-ratakan **'.$jenis.' jenis aspek**, **'.$jarang
                            .'** di antaranya muncul sekali saja - skornya hanya bisa 0 atau 1 dan tidak '
                            .'bermakna statistik. Baris terakhir mengeluarkannya. Laporkan **keduanya**: '
                            .'yang bersyarat support melengkapi, tidak menggantikan.';
                    }
                }

                $b[] = '- Pencocokan **parsial** menganggap satu label emas ditemukan bila ada kata-dasar '
                    .'yang sama dengan salah satu prediksi. Ini melonggarkan hukuman granularitas frasa '
                    .'(`dewan wakil rakyat` versus `dewan` + `rakyat`), yang bukan kesalahan model. Angka '
                    .'**ketat tetap dilaporkan sebagai batas bawah**, supaya pelonggaran itu tidak '
                    .'menyembunyikan apa pun.';
                $b[] = '- Kecocokan dinilai pada '.($aspek['match'] ?? 'bentuk dasar').
                    ' - bahasa Indonesia aglutinatif, dan membandingkan `pelayanannya` dengan `pelayanan` sebagai '
                    .'string persis menghitung pasangan yang benar dua kali salah.';
                $b[] = '';
                $b[] = '_Angka ini mengukur **ekstraksi aspek**, bukan polaritas per aspek. Sentimen per aspek '
                    .'dihasilkan pasca-pemrosesan di sisi layanan yang belum pernah diukur harness mana pun, jadi '
                    .'akurasi sentimen tingkat dokumen tidak berlaku untuknya._';
            } else {
                $b[] = '_Tidak dihitung: '.($aspek['message'] ?? '-').'_';
            }

            $b[] = '';
        }

        if ($topik = ($run->metrics['topic'] ?? null)) {
            $b[] = '#### Pemodelan topik';
            $b[] = '';

            if ($topik['available'] ?? false) {
                $b[] = '- Koherensi **c_v = '.$topik['c_v'].'** ('.$topik['c_v_label'].')';
                $b[] = '- c_npmi '.($topik['c_npmi'] ?? '-').', diversity '.($topik['diversity'] ?? '-')
                    .', outlier rate '.($topik['outlier_rate'] ?? '-');
                $b[] = '- '.$topik['topic_count'].' topik atas '.number_format($topik['corpus_texts']).' teks '
                    .'(**jumlah topik bergantung pada seed**: terukur 13,6 +- 4,98 pada lima seed, rentang 6-19, '
                    .'sementara c_v hanya 0,5023 +- 0,0221 - sebutkan seed dan rentangnya di naskah)';
                $b[] = '- Diukur pada **'.$topik['source'].'**';
                $b[] = '';
                $b[] = '_Koherensi tidak memakai label emas: ia mengukur keterkaitan kata di dalam korpus itu '
                    .'sendiri, bukan kecocokan dengan jawaban manusia. Karena itu tidak sebanding antar korpus '
                    .'dan tidak boleh disandingkan dengan F1 seolah setara. Diukur pada korpus penuh, bukan pada '
                    .'test set: golden dataset adalah sampel acak, sehingga kalimat pembentuk satu topik sebagian '
                    .'besar tidak ikut tersampel dan koherensinya tertekan._';

                if (($topik['c_npmi'] ?? 0) < 0) {
                    $b[] = '';
                    $b[] = '_c_npmi negatif sementara c_v positif adalah kritik klasik terhadap c_v: konfirmasi '
                        .'tak-langsung lewat kosinus antar-vektor NPMI dapat menaikkan skor walau pasangan katanya '
                        .'tidak benar-benar ko-okuren. Melaporkan keduanya sudah sikap yang benar; siapkan satu '
                        .'paragraf penjelasan._';
                }
            } else {
                $b[] = '_Tidak dihitung: '.($topik['message'] ?? '-').'_';
            }

            $b[] = '';
        }

        if ($segmen = ($run->metrics['segment'] ?? null)) {
            $b[] = '#### Rincian per segmen';
            $b[] = '';
            $b[] = '| Segmen | n | Akurasi | Macro-F1 |';
            $b[] = '|---|---|---|---|';

            foreach ($segmen as $nama => $skor) {
                $b[] = sprintf('| %s | %d | %s%% | %s%% |', $nama, $skor['n'], $skor['accuracy'], $skor['macro_f1']);
            }

            $b[] = '';
            $b[] = '_Segmen dengan n kecil punya selang kepercayaan lebar; jangan menyimpulkan perbedaan antar segmen dari selisih beberapa poin saja._';
            $b[] = '';
        }

        $b[] = '#### Reproduksi';
        $b[] = '';
        $b[] = '- Model: `'.($run->model_info['sentiment_source'] ?? 'tidak tercatat').'`';
        $b[] = '- Checkpoint hasil pelatihan ulang dimuat: '.(($run->model_info['sentiment_retrained'] ?? false) ? 'ya' : 'tidak');
        $b[] = '- Suhu kalibrasi: '.($run->model_info['sentiment_temperature'] ?? '-');
        $b[] = '- Dijalankan: '.$run->completed_at?->format('d F Y H:i');
        $b[] = '- Bootstrap macro-F1: '.($s['macro_f1_ci']['rounds'] ?? 0).' putaran, semai '.($s['macro_f1_ci']['seed'] ?? '-');
        $b[] = '';

        return $b;
    }

    private function tanda(?float $nilai): string
    {
        if ($nilai === null) {
            return '-';
        }

        return ($nilai > 0 ? '+' : '').number_format($nilai, 2).' poin';
    }
}
