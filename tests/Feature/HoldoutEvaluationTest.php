<?php

namespace Tests\Feature;

use App\Jobs\RetrainModel;
use App\Jobs\RunHoldoutEvaluation;
use App\Models\AnalysisResult;
use App\Models\EvaluationDataset;
use App\Models\EvaluationRun;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\Evaluation\ClassificationMetrics;
use App\Services\Evaluation\EvaluationComparisonService;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\HoldoutEvaluationService;
use App\Services\NLPApiService;
use App\Services\RetrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Evaluasi hold-out: golden dataset yang tidak pernah dilatihkan.
 *
 * Yang dijaga tes ini bukan sekadar "angkanya keluar", melainkan tiga janji
 * yang membuat angka itu layak dikutip di naskah: barisnya tidak bocor ke data
 * latih, datasetnya tidak berubah antar iterasi, dan perbandingan antar
 * iterasi diuji signifikansinya alih-alih dibaca dari selisih mentah.
 */
class HoldoutEvaluationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- metrik

    public function test_metrik_sentimen_cocok_dengan_hitungan_tangan(): void
    {
        $pairs = $this->pairsDari([
            ['positive', 'positive', 40],
            ['positive', 'neutral', 5],
            ['neutral', 'neutral', 25],
            ['neutral', 'negative', 5],
            ['negative', 'negative', 20],
            ['negative', 'positive', 5],
        ]);

        $hasil = app(ClassificationMetrics::class)->sentiment($pairs, 100, 1);

        $this->assertSame(100, $hasil['n']);
        $this->assertSame(85.0, $hasil['accuracy']);
        $this->assertSame(85, $hasil['correct']);

        // 45 baris berlabel positive adalah kelas terbanyak.
        $this->assertSame('positive', $hasil['majority_baseline']['label']);
        $this->assertSame(45.0, $hasil['majority_baseline']['accuracy']);

        // positive: tp=40, fp=5 (negative dinilai positive), fn=5
        $this->assertSame(88.89, $hasil['per_class']['positive']['precision']);
        $this->assertSame(88.89, $hasil['per_class']['positive']['recall']);
        $this->assertSame(45, $hasil['per_class']['positive']['support']);
    }

    public function test_macro_f1_berbeda_dari_weighted_pada_kelas_timpang(): void
    {
        // Kelas mayoritas sempurna, kelas minoritas gagal total. Weighted-F1
        // tetap tinggi; macro-F1 yang memperlihatkan kegagalannya. Ini alasan
        // macro-F1 dilaporkan, bukan hanya weighted.
        $pairs = $this->pairsDari([
            ['positive', 'positive', 90],
            ['negative', 'positive', 10],
        ]);

        $hasil = app(ClassificationMetrics::class)->sentiment($pairs, 0);

        $this->assertSame(90.0, $hasil['accuracy']);
        $this->assertGreaterThan($hasil['macro_f1'], $hasil['weighted_f1']);
        $this->assertLessThan(50.0, $hasil['macro_f1']);
    }

    public function test_selang_wilson_sesuai_nilai_baku(): void
    {
        // 90 dari 100 -> (0,8256; 0,9448) pada tabel Wilson 95%.
        $ci = app(ClassificationMetrics::class)->wilson(90, 100);

        $this->assertEqualsWithDelta(82.56, $ci['low'], 0.05);
        $this->assertEqualsWithDelta(94.48, $ci['high'], 0.05);
    }

    public function test_bootstrap_macro_f1_dapat_diulang(): void
    {
        $pairs = $this->pairsDari([
            ['positive', 'positive', 30],
            ['neutral', 'neutral', 30],
            ['negative', 'positive', 10],
            ['negative', 'negative', 30],
        ]);

        $metrics = app(ClassificationMetrics::class);
        $a = $metrics->sentiment($pairs, 200, 12345);
        $b = $metrics->sentiment($pairs, 200, 12345);

        // Semai yang sama harus memberi selang yang sama persis: angka di naskah
        // harus bisa dihitung ulang.
        $this->assertSame($a['macro_f1_ci']['low'], $b['macro_f1_ci']['low']);
        $this->assertSame($a['macro_f1_ci']['high'], $b['macro_f1_ci']['high']);
        $this->assertLessThanOrEqual($a['macro_f1'], $a['macro_f1_ci']['low']);
    }

    public function test_baris_yang_tidak_bisa_dinilai_dihitung_terpisah(): void
    {
        $pairs = [
            ['gold' => 'positive', 'predicted' => 'positive'],
            ['gold' => 'negative', 'predicted' => null],
            ['gold' => 'neutral', 'predicted' => ''],
        ];

        $hasil = app(ClassificationMetrics::class)->sentiment($pairs, 0);

        $this->assertSame(1, $hasil['n']);
        $this->assertSame(2, $hasil['unscorable']);
    }

    public function test_metrik_aspek_multilabel(): void
    {
        $hasil = app(ClassificationMetrics::class)->aspects([
            ['gold' => ['pelayanan', 'waktu'], 'predicted' => ['pelayanan']],
            ['gold' => ['sistem'], 'predicted' => ['sistem']],
            ['gold' => ['petugas'], 'predicted' => ['petugas', 'sistem']],
        ]);

        $this->assertSame(3, $hasil['tp']);
        $this->assertSame(1, $hasil['fp']);
        $this->assertSame(1, $hasil['fn']);
        $this->assertSame(75.0, $hasil['micro_f1']);
        $this->assertEqualsWithDelta(33.33, $hasil['exact_match'], 0.01);
    }

    // -------------------------------------------------------------- mcnemar

    public function test_mcnemar_memakai_binomial_eksak_pada_jumlah_kecil(): void
    {
        $banding = app(EvaluationComparisonService::class);

        // 1 dari 11 pada uji binomial dua sisi = 0,011719.
        $hasil = $banding->mcnemar(10, 1);

        $this->assertSame('binomial eksak', $hasil['method']);
        $this->assertEqualsWithDelta(0.011719, $hasil['p_value'], 0.000001);
        $this->assertTrue($hasil['significant']);
    }

    public function test_mcnemar_tidak_menyatakan_signifikan_untuk_selisih_kecil(): void
    {
        // Tiga baris memburuk, nol membaik: p = 0,25. Selisih sekecil ini tidak
        // boleh dilaporkan sebagai perubahan kinerja.
        $hasil = app(EvaluationComparisonService::class)->mcnemar(3, 0);

        $this->assertEqualsWithDelta(0.25, $hasil['p_value'], 0.0001);
        $this->assertFalse($hasil['significant']);
        $this->assertStringContainsString('kebetulan', $hasil['interpretation']);
    }

    public function test_mcnemar_tanpa_pembalikan(): void
    {
        $hasil = app(EvaluationComparisonService::class)->mcnemar(0, 0);

        $this->assertSame(1.0, $hasil['p_value']);
        $this->assertFalse($hasil['significant']);
    }

    // ------------------------------------------------------- impor & split

    public function test_impor_menerima_alias_kolom_dan_menolak_duplikat(): void
    {
        $path = $this->csv([
            'teks,sentimen,label_2,aspek,lembaga',
            '"Pelayanannya cepat sekali",positif,positif,pelayanan,Lembaga A',
            '"Pelayanannya cepat sekali!!",positif,positif,pelayanan,Lembaga A',
            '"Sistemnya error terus",negatif,negatif,sistem,Lembaga B',
            '"Kantor buka pukul delapan",netral,netral,,Lembaga B',
            '"Baris tanpa label sah",entahlah,,,Lembaga B',
        ]);

        $dibaca = app(GoldenDatasetService::class)->readFile($path);

        // Baris kedua hanya berbeda tanda baca - sidik jarinya sama, jadi
        // dianggap duplikat. Teks kembar akan menghitung kesalahan yang sama
        // dua kali dan menggeser metrik tanpa alasan.
        $this->assertCount(3, $dibaca['rows']);
        $this->assertCount(2, $dibaca['errors']);
        $this->assertSame('positive', $dibaca['rows'][0]['gold_sentiment']);
        $this->assertSame(['pelayanan'], $dibaca['rows'][0]['gold_aspects']);
        $this->assertSame('Lembaga A', $dibaca['rows'][0]['segment']);
    }

    public function test_impor_menolak_berkas_tanpa_kolom_label(): void
    {
        $path = $this->csv(['teks,catatan', '"Halo",apa saja']);

        $this->expectException(RuntimeException::class);
        app(GoldenDatasetService::class)->readFile($path);
    }

    public function test_kappa_antar_anotator_dihitung(): void
    {
        $dataset = $this->datasetDari([
            ['Bagus sekali', 'positive', 'positive'],
            ['Buruk sekali', 'negative', 'negative'],
            ['Biasa saja', 'neutral', 'neutral'],
            ['Lumayan juga', 'positive', 'neutral'],
        ]);

        $this->assertNotNull($dataset->annotation);
        $this->assertSame(4, $dataset->annotation['double_annotated']);
        // assertEquals, bukan assertSame: nilai bulat pulang dari kolom JSON
        // sebagai int, bukan float. Kelas bug yang sama pernah membuat satu
        // metrik tampil dua cara berbeda di halaman hasil.
        $this->assertEquals(75.0, $dataset->annotation['agreement']);
        $this->assertGreaterThan(0.5, $dataset->annotation['cohen_kappa']);
    }

    public function test_pemecahan_berstrata_dapat_diulang_dan_tidak_tumpang_tindih(): void
    {
        $baris = [];
        foreach (['positive', 'neutral', 'negative'] as $label) {
            for ($i = 0; $i < 20; $i++) {
                $baris[] = [
                    'text' => "{$label} contoh {$i}",
                    'text_hash' => hash('sha256', "{$label}-{$i}"),
                    'gold_sentiment' => $label,
                    'gold_aspects' => null,
                    'second_sentiment' => null,
                    'segment' => null,
                    'source_ref' => null,
                    'note' => null,
                ];
            }
        }

        $golden = app(GoldenDatasetService::class);
        $a = $golden->stratifiedSplit($baris, 0.2, 42);
        $b = $golden->stratifiedSplit($baris, 0.2, 42);

        $this->assertSame(
            array_column($a['test'], 'text_hash'),
            array_column($b['test'], 'text_hash'),
            'Semai yang sama harus menghasilkan pemecahan yang sama persis.'
        );

        $this->assertCount(12, $a['test']);
        $this->assertCount(48, $a['train']);

        $irisan = array_intersect(
            array_column($a['test'], 'text_hash'),
            array_column($a['train'], 'text_hash')
        );
        $this->assertEmpty($irisan, 'Baris uji tidak boleh ikut berada di data latih.');

        // Proporsi kelas dipertahankan: 4 dari tiap kelas.
        $hitung = array_count_values(array_column($a['test'], 'gold_sentiment'));
        $this->assertSame([4, 4, 4], [$hitung['positive'], $hitung['neutral'], $hitung['negative']]);
    }

    // ---------------------------------------------------------- pembekuan

    public function test_dataset_belum_beku_tidak_bisa_dievaluasi(): void
    {
        $dataset = $this->datasetDari([['Bagus', 'positive', null]], beku: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum dibekukan');

        app(HoldoutEvaluationService::class)->run($dataset);
    }

    public function test_dataset_dengan_riwayat_run_tidak_bisa_dibuka_pembekuannya(): void
    {
        $dataset = $this->datasetDari([['Bagus', 'positive', null]]);

        EvaluationRun::create([
            'evaluation_dataset_id' => $dataset->id,
            'status' => 'completed',
            'iteration' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        app(GoldenDatasetService::class)->unfreeze($dataset);
    }

    // ------------------------------------------------------------ evaluasi

    public function test_run_menyimpan_metrik_dan_prediksi_per_baris(): void
    {
        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan', 'positive', null],
            ['Sistemnya error terus', 'negative', null],
            ['Kantor buka pukul delapan', 'neutral', null],
        ]);

        $this->fakeNlp([
            ['sentiment' => 'positive', 'confidence' => 0.97],
            ['sentiment' => 'negative', 'confidence' => 0.93],
            // Baris ketiga sengaja salah, supaya metriknya tidak 100%.
            ['sentiment' => 'negative', 'confidence' => 0.55],
        ]);

        $run = app(HoldoutEvaluationService::class)->run($dataset, ['label' => 'Iterasi 0']);

        $this->assertSame('completed', $run->status);
        $this->assertEqualsWithDelta(66.67, $run->metrics['sentiment']['accuracy'], 0.01);
        $this->assertSame(3, $run->items()->count());
        $this->assertSame(1, $run->items()->where('sentiment_correct', false)->count());

        // Identitas model dicatat, kalau tidak dua run tidak bisa ditelusuri
        // berasal dari bobot mana.
        $this->assertSame('crypter70/IndoBERT-Sentiment-Analysis', $run->model_info['sentiment_source']);
        $this->assertNotEmpty($run->preprocessing_config);
    }

    public function test_prediksi_dipetakan_lewat_original_index_bukan_urutan(): void
    {
        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan', 'positive', null],
            ['Sistemnya error terus', 'negative', null],
            ['Kantor buka pukul delapan', 'neutral', null],
        ]);

        // Jalur batch mengembalikan prediksi dalam urutan acak beserta
        // original_index. Mengandalkan urutan larik akan menggeser seluruh
        // pasangan emas-prediksi dan menghasilkan metrik yang salah tanpa galat.
        $this->fakeNlp([
            ['sentiment' => 'neutral', 'confidence' => 0.9, 'original_index' => 2],
            ['sentiment' => 'positive', 'confidence' => 0.9, 'original_index' => 0],
            ['sentiment' => 'negative', 'confidence' => 0.9, 'original_index' => 1],
        ]);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertEquals(100.0, $run->metrics['sentiment']['accuracy']);
    }

    public function test_koherensi_dibaca_dari_korpus_studi_kasus(): void
    {
        // Koherensi diukur pada korpus penuh, bukan pada test set. Golden
        // dataset adalah sampel acak: kalimat pembentuk satu topik sebagian
        // besar tidak ikut tersampel, dan pada 300 dokumen pendek matriks
        // ko-okurensinya jarang sehingga c_v tertekan. Terukur di sisi layanan:
        // 300 teks -> 0,3597 ("lemah"), 1.139 teks -> 0,4498 ("baik").
        $korpus = $this->korpusDenganTopik(0.4498, 18, 1139);
        $dataset = $this->datasetSampel($korpus->id);

        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]]);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertTrue($run->metrics['topic']['available']);
        $this->assertEquals(0.4498, $run->metrics['topic']['c_v']);
        $this->assertSame(18, $run->metrics['topic']['topic_count']);
        $this->assertSame(1139, $run->metrics['topic']['corpus_texts']);
        $this->assertStringContainsString('bukan test set', $run->metrics['topic']['source']);

        // Labelnya harus berasal dari ResultPresenter, satu-satunya tempat
        // ambang c_v ditulis - kalau tidak, satu angka bisa dilabeli berbeda
        // di halaman hasil dan di halaman evaluasi.
        $this->assertSame(
            \App\Helpers\ResultPresenter::coherenceLabel(0.4498),
            $run->metrics['topic']['c_v_label']
        );
    }

    public function test_koherensi_tidak_menembak_layanan_saat_evaluasi(): void
    {
        // Dibaca dari hasil analisis yang tersimpan, bukan dengan menjalankan
        // pemodelan topik ulang: selain jauh lebih murah, itu menjamin angkanya
        // sama persis dengan yang tampil di halaman hasil untuk pengguna.
        $korpus = $this->korpusDenganTopik(0.4498, 18, 1139);
        $dataset = $this->datasetSampel($korpus->id);

        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]]);

        app(HoldoutEvaluationService::class)->run($dataset);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/analyze/topic'));
    }

    public function test_koherensi_dilewati_bila_korpus_belum_punya_hasil_topik(): void
    {
        $korpus = $this->korpusDenganTopik(null);
        $dataset = $this->datasetSampel($korpus->id);

        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]]);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertSame('completed', $run->status);
        $this->assertFalse($run->metrics['topic']['available']);
        $this->assertStringContainsString('pemodelan topik', $run->metrics['topic']['message']);
    }

    // ------------------------------------------------------------ aspek

    public function test_aspek_tidak_dihitung_bila_label_emas_belum_berbentuk_dasar(): void
    {
        // Lebih baik tidak ada angka daripada angka yang salah telak: bentuk
        // permukaan (`pelayanannya`) dibandingkan dengan bentuk dasar
        // (`pelayanan`) sebagai string persis menghitung tiap pasangan yang
        // BENAR dua kali salah. Terukur micro-F1 0,118 padahal seharusnya 0,941.
        $dataset = $this->datasetDenganAspek(normalisasi: false);

        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]], aspekNormal: true);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertFalse($run->metrics['aspect']['available']);
        $this->assertStringContainsString('bentuk dasar', $run->metrics['aspect']['message']);
    }

    public function test_aspek_dinilai_pada_bentuk_dasar_kedua_sisi(): void
    {
        $dataset = $this->datasetDenganAspek(normalisasi: true);

        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]], aspekNormal: true);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertTrue($run->metrics['aspect']['available']);
        $this->assertSame(100.0, (float) $run->metrics['aspect']['micro_f1']);
        $this->assertStringContainsString('bentuk dasar', $run->metrics['aspect']['match']);
    }

    public function test_aspek_ditolak_bila_layanan_belum_kirim_bentuk_dasar(): void
    {
        $dataset = $this->datasetDenganAspek(normalisasi: true);

        // Layanan versi lama hanya mengirim document_aspects.
        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]], aspekNormal: false);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertFalse($run->metrics['aspect']['available']);
        $this->assertStringContainsString('document_aspects_normalized', $run->metrics['aspect']['message']);
    }

    public function test_baris_tanpa_aspek_ikut_dinilai_sebagai_false_positive(): void
    {
        // Inti perbaikannya. Baris yang SUDAH ditinjau dan memang tidak punya
        // aspek (`[]`) wajib ikut dinilai: kalau hanya baris beraspek yang
        // dihitung, aspek yang dikarang model pada komentar yang tidak
        // membicarakan apa pun tidak pernah terhitung sebagai false positive,
        // dan presisi yang dilaporkan menjadi terlalu tinggi.
        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store([
            [
                'text' => 'Pelayanannya cepat sekali di kantor itu',
                'gold_sentiment' => 'positive',
                'gold_aspects' => ['pelayanan'],
            ],
            [
                'text' => 'Setuju sekali dengan pendapat itu semua',
                'gold_sentiment' => 'positive',
                'gold_aspects' => [],   // ditinjau, memang tidak ada aspek
            ],
        ], ['name' => 'Golden aspek', 'split' => 'test']);

        $dataset->items()->orderBy('id')->first()->update(['gold_aspects_normalized' => ['layan']]);
        $dataset->items()->orderBy('id')->skip(1)->first()->update(['gold_aspects_normalized' => []]);
        $dataset->update(['frozen_at' => now()]);

        Http::preventStrayRequests();
        Http::fake([
            '*/health' => Http::response(['models' => []], 200),
            '*/api/analyze/sentiment' => Http::response([
                'status' => 'success',
                'results' => ['predictions' => [
                    ['sentiment' => 'positive', 'confidence' => 0.9],
                    ['sentiment' => 'positive', 'confidence' => 0.9],
                ]],
            ], 200),
            // Model mengarang aspek pada baris kedua yang sebenarnya kosong.
            '*/api/analyze/aspect' => Http::response([
                'status' => 'success',
                'results' => [
                    'document_aspects' => [['pelayanannya'], ['pendapat']],
                    'document_aspects_normalized' => [['layan'], ['dapat']],
                ],
            ], 200),
        ]);

        $run = app(HoldoutEvaluationService::class)->run($dataset->fresh());
        $aspek = $run->metrics['aspect'];

        $this->assertTrue($aspek['available']);
        $this->assertSame(2, $aspek['n']);
        $this->assertSame(1, $aspek['tp']);
        $this->assertSame(1, $aspek['fp'], 'aspek karangan pada baris tanpa aspek harus terhitung');
        $this->assertSame(0, $aspek['fn']);
        $this->assertSame(50.0, (float) $aspek['exact_match']);
    }

    public function test_aspek_belum_ditinjau_membuat_metriknya_tidak_dihitung(): void
    {
        // null berarti belum ditinjau - berbeda dari `[]`. Selama masih ada yang
        // belum ditinjau, metriknya tidak dihitung sama sekali daripada dihitung
        // atas sebagian baris saja.
        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store([
            ['text' => 'Pelayanannya cepat sekali di kantor', 'gold_sentiment' => 'positive', 'gold_aspects' => ['pelayanan']],
            ['text' => 'Setuju sekali dengan pendapat itu', 'gold_sentiment' => 'positive'],
        ], ['name' => 'Golden aspek', 'split' => 'test']);

        $dataset->items()->orderBy('id')->first()->update(['gold_aspects_normalized' => ['layan']]);
        $dataset->update(['frozen_at' => now()]);

        $this->fakeNlp([
            ['sentiment' => 'positive', 'confidence' => 0.9],
            ['sentiment' => 'positive', 'confidence' => 0.9],
        ], aspekNormal: true);

        $run = app(HoldoutEvaluationService::class)->run($dataset->fresh());

        $this->assertFalse($run->metrics['aspect']['available']);
        $this->assertStringContainsString('belum ditinjau aspeknya', $run->metrics['aspect']['message']);
    }

    public function test_penanda_tidak_ada_aspek_terbaca_saat_impor(): void
    {
        $golden = app(GoldenDatasetService::class);

        // `-` berarti sudah ditinjau tanpa aspek; kosong berarti belum ditinjau.
        $path = $this->csv([
            'id,text,label,aspects',
            '1,"Pelayanannya cepat sekali di kantor",positif,pelayanan',
            '2,"Setuju sekali dengan pendapat itu",positif,-',
            '3,"Antrian panjang sekali tiap pagi",negatif,',
        ]);

        $baris = $golden->readFile($path)['rows'];

        $this->assertSame(['pelayanan'], $baris[0]['gold_aspects']);
        $this->assertSame([], $baris[1]['gold_aspects']);
        $this->assertNull($baris[2]['gold_aspects']);
    }

    public function test_pembekuan_mengisi_bentuk_dasar_dan_sidik_jari_isi(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api/normalize/aspects' => Http::response([
                'status' => 'success',
                'results' => ['normalized' => ['Pelayanan' => 'layan', 'pajaknya' => 'pajak']],
            ], 200),
        ]);

        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store([
            ['text' => 'Pelayanannya cepat sekali di kantor itu', 'gold_sentiment' => 'positive', 'gold_aspects' => ['Pelayanan']],
            ['text' => 'Pajaknya naik terus tiap tahun', 'gold_sentiment' => 'negative', 'gold_aspects' => ['pajaknya']],
        ], ['name' => 'Golden aspek', 'split' => 'test']);

        $beku = $golden->freeze($dataset);

        $this->assertSame(['layan'], $beku->items()->orderBy('id')->first()->gold_aspects_normalized);

        // Sidik jari isi membuktikan kepada pembaca naskah bahwa tiap iterasi
        // mengukur baris yang sama, tanpa perlu mempercayai aplikasinya.
        $this->assertSame($golden->contentHash($beku), $beku->content_hash);
        $this->assertSame(64, strlen($beku->content_hash));
    }

    public function test_daya_uji_dilaporkan_pada_hasil_yang_tidak_signifikan(): void
    {
        // Melaporkan p saja menyisakan pertanyaan "seberapa besar perbaikan yang
        // sebenarnya bisa terdeteksi". Angkanya cocok dengan tabel daya uji di
        // tinjauan nlp-api-service: b=4 -> butuh c>=13.
        $hasil = app(EvaluationComparisonService::class)->mcnemar(4, 9, 300);

        $this->assertFalse($hasil['significant']);
        $this->assertSame(13, $hasil['power']['min_c']);
        $this->assertSame(9, $hasil['power']['net']);
        $this->assertSame(3.0, $hasil['power']['points']);
        $this->assertStringContainsString('minimal 13 baris membaik', $hasil['interpretation']);
    }

    public function test_daya_uji_cocok_dengan_tabel_rujukan(): void
    {
        $banding = app(EvaluationComparisonService::class);

        foreach ([0 => 6, 2 => 10, 4 => 13, 6 => 17, 8 => 20] as $b => $harapan) {
            $this->assertSame($harapan, $banding->powerNote($b)['min_c'], "b={$b}");
        }
    }

    public function test_tabel_reliabilitas_dihitung_dari_golden_dataset(): void
    {
        // Yang membenarkan ambang tinjauan. Ambang menyatakan "tinjau bila
        // perkiraan salahnya di atas sekian" - klaim itu hanya sah bila
        // keyakinan model memang menaksir peluang benarnya, dan di sinilah hal
        // itu diuji pada korpus sendiri, bukan pada set uji milik layanan.
        config()->set('analysis.confidence.error_tolerance', 0.10);

        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan sekali di kantor', 'positive', null],
            ['Sistemnya error terus tiap dibuka', 'negative', null],
            ['Kantor buka pukul delapan pagi', 'neutral', null],
            ['Antrean panjang sekali tiap hari', 'negative', null],
        ]);

        $this->fakeNlp([
            ['sentiment' => 'positive', 'confidence' => 0.97],   // pita atas, benar
            ['sentiment' => 'negative', 'confidence' => 0.95],   // pita atas, benar
            ['sentiment' => 'positive', 'confidence' => 0.75],   // pita tengah, SALAH
            ['sentiment' => 'positive', 'confidence' => 0.60],   // pita bawah, SALAH
        ]);

        $rel = app(HoldoutEvaluationService::class)->run($dataset)->metrics['reliability'];

        $this->assertTrue($rel['available']);
        $this->assertSame(4, $rel['n']);

        $perPita = collect($rel['bins'])->keyBy('range');

        // Pita teratas: dua baris, keduanya benar.
        $this->assertSame(2, $perPita['0.90-1.00']['n']);
        $this->assertEquals(100.0, $perPita['0.90-1.00']['accuracy']);

        // Pita 0,70-0,90: satu baris, salah - model terlalu percaya diri, dan
        // selisihnya negatif. Justru itu alasan pita ini ditinjau manusia.
        $this->assertSame(1, $perPita['0.70-0.90']['n']);
        $this->assertEquals(0.0, $perPita['0.70-0.90']['accuracy']);
        $this->assertLessThan(0, $perPita['0.70-0.90']['gap']);

        // Batas pitanya harus sama persis dengan yang dilihat pengguna di
        // halaman hasil, kalau tidak tabel ini tidak membenarkan apa pun.
        $this->assertSame(
            app(\App\Services\ConfidenceReportService::class)->bandBounds()['tinggi'],
            0.9,
        );
    }

    public function test_rincian_per_segmen_dihitung(): void
    {
        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan', 'positive', null, 'Lembaga A'],
            ['Sistemnya error terus', 'negative', null, 'Lembaga A'],
            ['Kantor buka pukul delapan', 'neutral', null, 'Lembaga B'],
        ]);

        $this->fakeNlp([
            ['sentiment' => 'positive', 'confidence' => 0.9],
            ['sentiment' => 'negative', 'confidence' => 0.9],
            ['sentiment' => 'positive', 'confidence' => 0.6],
        ]);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $this->assertEquals(100.0, $run->metrics['segment']['Lembaga A']['accuracy']);
        $this->assertEquals(0.0, $run->metrics['segment']['Lembaga B']['accuracy']);
    }

    // ------------------------------------------------------------ kebocoran

    public function test_koreksi_yang_ada_di_golden_dataset_tidak_ikut_dilatih(): void
    {
        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan sekali', 'positive', null],
        ]);

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis uji',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => ['a'],
        ]);

        // Teks yang sama persis dengan baris golden, hanya berbeda tanda baca
        // dan huruf besar - normalisasi sidik jari harus tetap menangkapnya.
        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'PELAYANANNYA MEMUASKAN SEKALI!!',
            'predicted_sentiment' => 'neutral',
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_at' => now(),
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'Kalimat lain yang tidak ada di golden',
            'predicted_sentiment' => 'neutral',
            'corrected_sentiment' => 'negative',
            'is_corrected' => true,
            'verified_at' => now(),
        ]);

        $retraining = app(RetrainingService::class);
        $items = $retraining->correctedItems();

        $this->assertCount(1, $items);
        $this->assertSame(1, $retraining->heldOutCount());
        $this->assertSame('Kalimat lain yang tidak ada di golden', $items->first()->text_content);

        $laporan = app(GoldenDatasetService::class)->leakageReport($dataset);
        $this->assertSame(1, $laporan['overlap']);
        $this->assertSame(1, $laporan['corrected_overlap']);
    }

    public function test_dataset_yang_belum_beku_tidak_menahan_data_latih(): void
    {
        // Dataset yang masih disusun belum tentu menjadi test set. Memblokir
        // koreksi karena berkas percobaan akan membuang data latih yang sah.
        $this->datasetDari([['Pelayanannya memuaskan sekali', 'positive', null]], beku: false);

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis uji',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => ['a'],
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'Pelayanannya memuaskan sekali',
            'predicted_sentiment' => 'neutral',
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_at' => now(),
        ]);

        $this->assertCount(1, app(RetrainingService::class)->correctedItems());
    }

    public function test_baris_uji_disembunyikan_dari_halaman_koreksi(): void
    {
        $pemilik = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $pemilik->id,
            'title' => 'Analisis uji',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => ['a', 'b'],
        ]);

        foreach ([
            'Pelayanannya memuaskan sekali di kantor itu',
            'Kalimat lain yang tidak masuk golden dataset',
        ] as $teks) {
            TrainingItem::create([
                'text_analysis_id' => $analysis->id,
                'text_content' => $teks,
                'predicted_sentiment' => 'neutral',
                'confidence_score' => 0.5,
            ]);
        }

        $this->datasetDari([['Pelayanannya memuaskan sekali di kantor itu', 'positive', null]]);

        // Baris uji memang sudah disingkirkan dari pelatihan, tetapi tanpa ini
        // pengguna tetap disodori baris yang hasilnya dibuang diam-diam -
        // tenaganya terbuang, dan pada studi kasus itu terasa.
        $this->actingAs($pemilik)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->assertSee('Kalimat lain yang tidak masuk golden dataset')
            ->assertDontSee('Pelayanannya memuaskan sekali di kantor itu');
    }

    public function test_baris_uji_tidak_bisa_ditandai_terkoreksi(): void
    {
        $pemilik = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $pemilik->id,
            'title' => 'Analisis uji',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => ['a'],
        ]);

        $item = TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'Pelayanannya memuaskan sekali di kantor itu',
            'predicted_sentiment' => 'neutral',
            'confidence_score' => 0.5,
        ]);

        $this->datasetDari([['Pelayanannya memuaskan sekali di kantor itu', 'positive', null]]);

        // Penjaga kedua: kiriman formulir lama atau permintaan yang dibuat
        // langsung tetap tidak boleh menandai baris uji sebagai terverifikasi.
        $this->actingAs($pemilik)->post(route('analysis.feedback.store', $analysis->id), [
            'corrections' => [
                ['item_id' => $item->id, 'corrected_sentiment' => 'negative'],
            ],
        ]);

        $this->assertFalse((bool) $item->fresh()->is_corrected);
        $this->assertNull($item->fresh()->corrected_sentiment);
    }

    // ------------------------------------------------- pemicu evaluasi

    public function test_pelatihan_ulang_tidak_memicu_evaluasi(): void
    {
        Queue::fake();

        $this->datasetDari([['Pelayanannya memuaskan', 'positive', null]]);

        Http::fake([
            '*/api/retrain/sentiment' => Http::response([
                'status' => 'success',
                'results' => [
                    'saved' => true,
                    'metrics_after' => ['weighted_f1' => 0.91],
                ],
            ], 200),
        ]);

        $training = ModelTraining::create([
            'model_type' => 'sentiment',
            'status' => 'pending',
            'total_samples' => 12,
            'epochs' => 3,
            'learning_rate' => 0.00002,
        ]);

        (new RetrainModel($training, $this->sampelLatih()))
            ->handle(app(NLPApiService::class));

        $this->assertSame('completed', $training->fresh()->status);

        // Evaluasi adalah alat penyusunan skripsi: kapan sebuah iterasi diukur
        // adalah keputusan peneliti, bukan efek samping antrean. Tes ini ada
        // supaya pemicu otomatis tidak diam-diam masuk kembali.
        Queue::assertNotPushed(RunHoldoutEvaluation::class);
    }

    // ---------------------------------------------------------- halaman admin

    public function test_halaman_evaluasi_tertutup_bagi_pengguna_biasa(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.evaluation.index'))
            ->assertForbidden();
    }

    public function test_halaman_evaluasi_tampil_saat_belum_ada_dataset(): void
    {
        // Keadaan pertama yang dilihat siapa pun. Blok kebocoran punya cabang
        // tersendiri untuk "belum ada dataset beku" dan cabang itu perlu diuji
        // - halaman evaluasi yang 500 saat kosong tidak akan pernah dipakai.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.index'))
            ->assertOk()
            ->assertSee('Belum ada golden dataset');
    }

    public function test_halaman_evaluasi_tampil_untuk_admin(): void
    {
        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan', 'positive', 'positive'],
            ['Sistemnya error terus', 'negative', 'negative'],
        ]);

        $this->fakeNlp([
            ['sentiment' => 'positive', 'confidence' => 0.9],
            ['sentiment' => 'positive', 'confidence' => 0.6],
        ]);

        app(HoldoutEvaluationService::class)->run($dataset, ['label' => 'Iterasi 0']);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.evaluation.index'))
            ->assertOk()
            ->assertSee($dataset->name);

        $this->actingAs($admin)
            ->get(route('admin.evaluation.show', $dataset->id))
            ->assertOk()
            ->assertSee('Iterasi 0')
            ->assertSee('Tebak kelas mayoritas');
    }

    public function test_halaman_evaluasi_menyatakan_batas_tafsir_aspek_dan_topik(): void
    {
        // Dua kesalahan tafsir yang paling mudah terjadi saat angka disalin ke
        // naskah: mengira F1 aspek berarti sentimen per aspek sudah terukur,
        // dan menulis "menghasilkan K topik" seolah K itu tetap. Keduanya harus
        // tertulis di tempat angkanya dibaca, bukan hanya di dokumentasi.
        $korpus = $this->korpusDenganTopik(0.4498, 18, 1139);

        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store([[
            'text' => 'Pelayanannya cepat sekali di kantor itu',
            'gold_sentiment' => 'positive',
            'gold_aspects' => ['pelayanan'],
        ]], ['name' => 'Golden aspek', 'split' => 'test']);

        $dataset->items()->update(['gold_aspects_normalized' => json_encode(['layan'])]);
        $dataset->update([
            'frozen_at' => now(),
            'sampling_plan' => ['analysis_ids' => [$korpus->id]],
        ]);

        $this->fakeNlp([['sentiment' => 'positive', 'confidence' => 0.9]], aspekNormal: true);

        app(HoldoutEvaluationService::class)->run($dataset->fresh());

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.show', $dataset->id))
            ->assertOk()
            ->assertSee('ekstraksi aspek')
            ->assertSee('bergantung seed');
    }

    public function test_halaman_hasil_tidak_mengklaim_akurasi_untuk_sentimen_per_aspek(): void
    {
        $pemilik = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $pemilik->id,
            'title' => 'Analisis aspek',
            'analysis_type' => 'aspect',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => ['Pelayanannya cepat sekali di kantor itu'],
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [[
                'text' => 'Pelayanannya cepat sekali di kantor itu',
                'sentiment' => 'positive',
                'confidence' => 0.9,
                'original_index' => 0,
            ]],
            'aspect_results' => [[
                'aspect' => 'pelayanan',
                'count' => 1,
                'sentiments' => ['positive' => 100, 'neutral' => 0, 'negative' => 0],
            ]],
        ]);

        // Persentase per aspek berasal dari pasca-pemrosesan tingkat klausa yang
        // belum pernah diukur harness mana pun. Tanpa kalimat ini pembaca akan
        // mengira akurasi model yang dilaporkan berlaku untuknya.
        $this->actingAs($pemilik)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertSee('tingkat klausa per aspek');
    }

    public function test_halaman_training_menyatakan_metriknya_bukan_hold_out(): void
    {
        // Grafik di halaman training dihitung atas data yang ikut dilatihkan.
        // Tanpa keterangan itu, angkanya mudah dikira bukti perbaikan model.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.training.index'))
            ->assertOk()
            ->assertSee('atas data koreksi yang ikut dilatihkan', false);
    }

    public function test_unduhan_baris_salah_berisi_hanya_yang_salah(): void
    {
        $dataset = $this->datasetDari([
            ['Pelayanannya memuaskan', 'positive', null],
            ['Sistemnya error terus', 'negative', null],
        ]);

        $this->fakeNlp([
            ['sentiment' => 'positive', 'confidence' => 0.9],
            ['sentiment' => 'positive', 'confidence' => 0.6],
        ]);

        $run = app(HoldoutEvaluationService::class)->run($dataset);

        $respons = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.errors', $run->id));

        $respons->assertOk();
        $isi = $respons->streamedContent();

        $this->assertStringContainsString('Sistemnya error terus', $isi);
        $this->assertStringNotContainsString('Pelayanannya memuaskan', $isi);
    }

    // ------------------------------------------------------------------ util

    /**
     * NLPApiService menolak kiriman di bawah MIN_RETRAIN_SAMPLES sebelum
     * menyentuh HTTP, jadi fixture-nya harus melewati ambang itu.
     */
    private function sampelLatih(int $jumlah = 12): array
    {
        $data = [];

        for ($i = 0; $i < $jumlah; $i++) {
            $data[] = ['text' => "Contoh kalimat ke-{$i}", 'label' => 'positive'];
        }

        return $data;
    }

    private function korpusDenganTopik(?float $cv, int $topik = 18, int $teks = 1139): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus studi kasus',
            'analysis_type' => 'combined',
            'input_type' => 'csv',
            'status' => 'completed',
            'raw_data' => array_fill(0, $teks, 'Komentar warga tentang pelayanan lembaga tersebut'),
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'topic_results' => $cv === null ? ['topics' => []] : [
                'topics' => array_fill(0, $topik, ['topic_id' => 0, 'words' => ['a', 'b']]),
                'quality' => ['c_v' => $cv, 'c_npmi' => 0.07, 'diversity' => 0.961, 'outlier_rate' => 0.11],
            ],
        ]);

        return $analysis;
    }

    private function datasetSampel(int $korpusId): EvaluationDataset
    {
        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store(
            [['text' => 'Pelayanannya memuaskan sekali', 'gold_sentiment' => 'positive']],
            ['name' => 'Golden uji', 'split' => 'test']
        );

        $dataset->update(['sampling_plan' => ['analysis_ids' => [$korpusId]]]);
        $golden->freeze($dataset->fresh());

        return $dataset->fresh();
    }

    private function datasetDenganAspek(bool $normalisasi): EvaluationDataset
    {
        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store([[
            'text' => 'Pelayanannya cepat sekali di kantor itu',
            'gold_sentiment' => 'positive',
            'gold_aspects' => ['pelayanan'],
        ]], ['name' => 'Golden aspek', 'split' => 'test']);

        if ($normalisasi) {
            $dataset->items()->update(['gold_aspects_normalized' => json_encode(['layan'])]);
        }

        $dataset->update(['frozen_at' => now()]);

        return $dataset->fresh();
    }

    private function pairsDari(array $spesifikasi): array
    {
        $pairs = [];

        foreach ($spesifikasi as [$emas, $prediksi, $jumlah]) {
            for ($i = 0; $i < $jumlah; $i++) {
                $pairs[] = ['gold' => $emas, 'predicted' => $prediksi];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: ?string, 3?: ?string}>  $baris
     */
    private function datasetDari(array $baris, bool $beku = true): EvaluationDataset
    {
        $siap = [];

        foreach ($baris as $satu) {
            $siap[] = [
                'text' => $satu[0],
                'gold_sentiment' => $satu[1],
                'second_sentiment' => $satu[2] ?? null,
                'gold_aspects' => null,
                'segment' => $satu[3] ?? null,
                'source_ref' => null,
                'note' => null,
            ];
        }

        $golden = app(GoldenDatasetService::class);
        $dataset = $golden->store($siap, ['name' => 'Golden uji', 'split' => 'test']);

        if ($beku) {
            $golden->freeze($dataset);
        }

        return $dataset->fresh();
    }

    private function fakeNlp(array $predictions, bool $aspekNormal = false): void
    {
        // Permintaan yang tidak cocok dengan satu pun pola di bawah akan
        // menembus ke layanan sungguhan. Ini bukan hipotesis: saat koherensi
        // topik ditambahkan, /api/analyze/topic sempat lolos dan durasi suite
        // naik dari 2 menjadi 19 detik - tes yang diam-diam menuntut Python
        // berjalan. preventStrayRequests membuat kelalaian itu gagal keras.
        Http::preventStrayRequests();

        Http::fake([
            '*/health' => Http::response([
                'status' => 'healthy',
                'models' => [
                    'sentiment_source' => 'crypter70/IndoBERT-Sentiment-Analysis',
                    'sentiment_base' => 'indobenchmark/indobert-base-p1',
                    'sentiment_temperature' => 2.7748,
                    'sentiment_review_threshold' => 0.94,
                    'sentiment_retrained' => false,
                    'aspect_retrained' => true,
                ],
            ], 200),
            '*/api/analyze/sentiment' => Http::response([
                'status' => 'success',
                'results' => ['predictions' => $predictions],
            ], 200),
            '*/api/analyze/aspect' => Http::response([
                'status' => 'success',
                'results' => $aspekNormal
                    ? ['document_aspects' => [['pelayanannya']], 'document_aspects_normalized' => [['layan']]]
                    : ['document_aspects' => [['pelayanannya']]],
            ], 200),
            '*/api/normalize/aspects' => Http::response([
                'status' => 'success',
                'results' => ['normalized' => ['pelayanan' => 'layan']],
            ], 200),
        ]);
    }

    private function csv(array $baris): string
    {
        $path = tempnam(sys_get_temp_dir(), 'golden').'.csv';
        file_put_contents($path, implode("\n", $baris));

        return $path;
    }
}
