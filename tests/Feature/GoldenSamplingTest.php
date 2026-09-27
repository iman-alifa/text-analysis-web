<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\EvaluationDataset;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\Evaluation\GoldenDatasetService;
use App\Services\Evaluation\GoldenSamplingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Pengambilan sampel untuk golden dataset.
 *
 * Yang diuji bukan hanya "sampelnya keluar", melainkan sifat-sifat yang membuat
 * sampel itu sah dipakai sebagai test set: acak dan bisa diulang, berstrata
 * pada sifat data, bersih dari baris yang sudah menjadi data latih, dan berkas
 * anotasinya tidak membocorkan tebakan model kepada anotator.
 */
class GoldenSamplingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sampel_dapat_diulang_dengan_semai_yang_sama(): void
    {
        $this->korpus(60);

        $sampling = app(GoldenSamplingService::class);
        $a = $sampling->sample(['analysis_ids' => [1], 'size' => 20, 'seed' => 7]);
        $b = $sampling->sample(['analysis_ids' => [1], 'size' => 20, 'seed' => 7]);
        $c = $sampling->sample(['analysis_ids' => [1], 'size' => 20, 'seed' => 8]);

        $hashA = array_column($a['rows'], 'text_hash');
        $hashB = array_column($b['rows'], 'text_hash');
        $hashC = array_column($c['rows'], 'text_hash');

        $this->assertSame($hashA, $hashB, 'Semai yang sama harus memberi sampel yang sama persis.');
        $this->assertNotSame($hashA, $hashC, 'Semai berbeda harus memberi sampel berbeda.');
        $this->assertCount(20, $a['rows']);
    }

    public function test_stratifikasi_membagi_jatah_proporsional(): void
    {
        // 60 teks: 30 Lembaga A, 20 Lembaga B, 10 Lembaga C.
        $this->korpus(60, kolom: 'lembaga', pembagian: ['Lembaga A' => 30, 'Lembaga B' => 20, 'Lembaga C' => 10]);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [1],
            'size' => 30,
            'seed' => 42,
            'stratify_by' => 'lembaga',
        ]);

        $hitung = array_count_values(array_column($hasil['rows'], 'segment'));

        $this->assertSame(15, $hitung['Lembaga A']);
        $this->assertSame(10, $hitung['Lembaga B']);
        $this->assertSame(5, $hitung['Lembaga C']);
        $this->assertSame(30, $hasil['plan']['sampled']);
    }

    public function test_baris_yang_koreksinya_sudah_dilatihkan_tidak_ikut_terambil(): void
    {
        $analysis = $this->korpus(30);

        // Baris ke-0 dikoreksi SEBELUM sebuah pelatihan, jadi sudah dilihat
        // model. Memasukkannya ke test set adalah kebocoran sejak awal.
        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => $analysis->raw_data[0],
            'predicted_sentiment' => 'neutral',
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_at' => now()->subHour(),
        ]);
        \App\Models\ModelTraining::create([
            'model_type' => 'sentiment', 'status' => 'completed', 'total_samples' => 60,
            'epochs' => 3, 'learning_rate' => 0.00002, 'completed_at' => now(),
        ]);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [$analysis->id],
            'size' => 30,
            'seed' => 42,
        ]);

        $this->assertSame(1, $hasil['plan']['rejected']['sudah_dikoreksi']);
        $this->assertNotContains($analysis->raw_data[0], array_column($hasil['rows'], 'text'));
        $this->assertCount(29, $hasil['rows']);
    }

    public function test_koreksi_yang_belum_dilatihkan_boleh_terambil_dan_dicatat(): void
    {
        // Fase evaluasi kuesioner: pelatihan ditunda, jadi koreksi pengguna
        // belum pernah dilihat model. Mengecualikannya justru membuat sampel
        // tidak acak - ajakan koreksi mendahulukan baris yang paling ragu.
        // Setelah dibekukan, correctedItems() menyingkirkannya dari pelatihan.
        $analysis = $this->korpus(30);

        // created_at tidak bisa diisi massal; setel langsung.
        \App\Models\ModelTraining::create([
            'model_type' => 'sentiment', 'status' => 'completed', 'total_samples' => 60,
            'epochs' => 3, 'learning_rate' => 0.00002, 'completed_at' => now()->subDay(),
        ])->forceFill(['created_at' => now()->subDay()])->save();
        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => $analysis->raw_data[0],
            'predicted_sentiment' => 'neutral',
            'corrected_sentiment' => 'positive',
            'is_corrected' => true,
            'verified_at' => now(),
        ]);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [$analysis->id],
            'size' => 30,
            'seed' => 42,
        ]);

        $this->assertSame(0, $hasil['plan']['rejected']['sudah_dikoreksi']);
        $this->assertContains($analysis->raw_data[0], array_column($hasil['rows'], 'text'));
        $this->assertSame(1, $hasil['plan']['koreksi_belum_dilatih_terambil']);
    }

    public function test_teks_pendek_dan_duplikat_disaring(): void
    {
        TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus uji',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => [
                'Pelayanan di kantor ini sangat memuaskan sekali',
                'PELAYANAN DI KANTOR INI SANGAT MEMUASKAN SEKALI!!',   // duplikat
                'mantap',                                              // terlalu pendek
                'Sistem antrean daringnya sering gagal saat diakses',
            ],
        ]);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [1],
            'size' => 10,
            'seed' => 42,
        ]);

        $this->assertCount(2, $hasil['rows']);
        $this->assertSame(1, $hasil['plan']['rejected']['duplikat']);
        $this->assertSame(1, $hasil['plan']['rejected']['terlalu_pendek']);
    }

    public function test_baris_yang_sudah_ada_di_golden_lain_tidak_terambil_dua_kali(): void
    {
        $analysis = $this->korpus(20);

        app(GoldenDatasetService::class)->store([[
            'text' => $analysis->raw_data[0],
            'gold_sentiment' => 'positive',
        ]], ['name' => 'Golden lama', 'split' => 'test']);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [$analysis->id],
            'size' => 20,
            'seed' => 42,
        ]);

        $this->assertSame(1, $hasil['plan']['rejected']['sudah_di_golden']);
        $this->assertCount(19, $hasil['rows']);
    }

    public function test_rencana_pengambilan_tersimpan_bersama_datasetnya(): void
    {
        $this->korpus(30, kolom: 'lembaga', pembagian: ['Lembaga A' => 20, 'Lembaga B' => 10]);

        $sampling = app(GoldenSamplingService::class);
        $hasil = $sampling->sample([
            'analysis_ids' => [1],
            'size' => 15,
            'seed' => 99,
            'stratify_by' => 'lembaga',
        ]);

        $dataset = $sampling->store($hasil['rows'], $hasil['plan'], ['name' => 'Sampel uji', 'split' => 'test']);

        // Prosedur pengambilan sampel adalah bagian dari metodologi yang harus
        // dilaporkan; yang tidak tersimpan pasti terlupa saat menulis naskah.
        $this->assertSame(99, $dataset->sampling_plan['seed']);
        $this->assertSame('lembaga', $dataset->sampling_plan['stratify_by']);
        $this->assertStringContainsString('tidak memakai keyakinan model', $dataset->sampling_plan['method']);
        $this->assertSame(['Lembaga A' => ['tersedia' => 20, 'diambil' => 10], 'Lembaga B' => ['tersedia' => 10, 'diambil' => 5]],
            $dataset->sampling_plan['strata']);
    }

    public function test_ukuran_bisa_disebut_sebagai_persen_kolam_layak(): void
    {
        // 40 baris kandidat; 25% berarti 10 baris. Penyebutnya adalah kolam
        // yang LOLOS penyaringan, bukan jumlah baris mentah - kalau tidak,
        // "10%" berarti porsi berbeda-beda tergantung berapa banyak emotikon
        // dan duplikat yang kebetulan ada di berkas.
        $this->korpus(40);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [1],
            'percent' => 25,
            'seed' => 42,
        ]);

        $this->assertSame(40, $hasil['plan']['candidates']);
        $this->assertSame(10, $hasil['plan']['sampled']);
        $this->assertSame(25.0, $hasil['plan']['requested_percent']);
    }

    public function test_persen_dihitung_setelah_penyaringan_bukan_dari_baris_mentah(): void
    {
        TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus campur',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => array_merge(
                array_map(fn ($i) => "Komentar warga nomor {$i} tentang pelayanan lembaga", range(1, 20)),
                array_fill(0, 20, 'aaa'),          // terlalu pendek
                ['❤️❤️👍👍👍❤️❤️👍👍👍❤️❤️👍👍'],  // panjang tapi tanpa isi
            ),
        ]);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [1],
            'percent' => 50,
            'seed' => 42,
        ]);

        // 41 baris mentah, tetapi hanya 20 yang layak; 50% dari 20 = 10.
        $this->assertSame(20, $hasil['plan']['candidates']);
        $this->assertSame(10, $hasil['plan']['sampled']);
        $this->assertSame(1, $hasil['plan']['rejected']['tidak_dinilai']);
    }

    public function test_teks_tanpa_huruf_tidak_pernah_terambil(): void
    {
        // Penyaringan panjang saja tidak cukup: untaian emotikon panjang lolos
        // dari ambang 20 karakter tetapi tetap tidak bisa dianotasi siapa pun.
        TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus emotikon',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => [
                'Pelayanan di kantor itu sangat memuaskan',
                '❤️❤️👍👍👍❤️❤️👍👍👍❤️❤️👍👍👍',
                '......................',
                'Sistem antreannya sering gagal saat diakses',
            ],
        ]);

        $hasil = app(GoldenSamplingService::class)->sample([
            'analysis_ids' => [1],
            'size' => 10,
            'seed' => 42,
        ]);

        $this->assertCount(2, $hasil['rows']);
        $this->assertSame(2, $hasil['plan']['rejected']['tidak_dinilai']);
    }

    public function test_dataset_hasil_sampel_lahir_tanpa_label_dan_belum_bisa_dibekukan(): void
    {
        $dataset = $this->datasetSampel();

        $this->assertSame(10, $dataset->item_count);
        $this->assertSame(10, $dataset->unlabeledCount());
        $this->assertSame(10, $dataset->label_distribution['belum_dilabeli']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tanpa label emas');

        app(GoldenDatasetService::class)->freeze($dataset);
    }

    public function test_berkas_anotasi_tidak_memuat_prediksi_model(): void
    {
        $dataset = $this->datasetSampel();
        $sampling = app(GoldenSamplingService::class);

        $header = $sampling->annotationHeader();

        // Anotator yang melihat tebakan model cenderung menyetujuinya, dan
        // akurasi terukur naik tanpa modelnya berubah.
        $this->assertNotContains('predicted', $header);
        $this->assertNotContains('confidence', $header);
        $this->assertNotContains('prediksi', $header);

        // id wajib ada: itu yang memasangkan baris kembali saat diunggah.
        $this->assertSame('id', $header[0]);
        $this->assertCount(10, $sampling->annotationRows($dataset));
    }

    public function test_anotasi_diterapkan_lewat_id_lalu_dataset_bisa_dibekukan(): void
    {
        $dataset = $this->datasetSampel();
        $sampling = app(GoldenSamplingService::class);

        $baris = ['id,text,label,label_2,aspects,segment,note'];

        foreach ($sampling->annotationRows($dataset) as $indeks => $satu) {
            $baris[] = sprintf(
                '%d,"%s",%s,%s,,,',
                $satu[0],
                str_replace('"', '', $satu[1]),
                ['positif', 'netral', 'negatif'][$indeks % 3],
                $indeks < 4 ? 'positif' : '',
            );
        }

        $baris[] = '99999,"Baris yang tidak ada di dataset",positif,,,,';

        $hasil = $sampling->applyAnnotations($dataset, $this->csv($baris));

        $this->assertSame(10, $hasil['filled']);
        $this->assertSame(1, $hasil['unmatched']);
        $this->assertSame(0, $dataset->fresh()->unlabeledCount());

        $beku = app(GoldenDatasetService::class)->freeze($dataset->fresh());
        $this->assertTrue($beku->isFrozen());

        // Anotator kedua terisi pada sebagian baris, jadi kappa ikut terhitung.
        $this->assertNotNull($beku->annotation);
    }

    public function test_anotasi_tetap_dikenali_walau_teksnya_tersunting(): void
    {
        $dataset = $this->datasetSampel();
        $sampling = app(GoldenSamplingService::class);
        $pertama = $dataset->items()->orderBy('id')->first();

        // Anotator merapikan teks di spreadsheet. Pencocokan lewat id membuat
        // barisnya tetap dikenali; kalau hanya mengandalkan sidik jari teks,
        // baris itu diam-diam hilang dari test set.
        $isi = [
            'id,text,label,label_2,aspects,segment,note',
            $pertama->id.',"teks ini sudah diubah anotator",negatif,,,,',
        ];

        $hasil = $sampling->applyAnnotations($dataset, $this->csv($isi));

        $this->assertSame(1, $hasil['filled']);
        $this->assertSame(0, $hasil['unmatched']);
        $this->assertSame('negative', $pertama->fresh()->gold_sentiment);
    }

    public function test_anotasi_ditolak_setelah_dataset_dibekukan(): void
    {
        $dataset = $this->datasetSampel();

        $dataset->items()->update(['gold_sentiment' => 'positive']);
        app(GoldenDatasetService::class)->freeze($dataset->fresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah dibekukan');

        app(GoldenSamplingService::class)->applyAnnotations(
            $dataset->fresh(),
            $this->csv(['id,text,label', '1,"apa saja",negatif'])
        );
    }

    public function test_berkas_anotasi_bisa_menyertakan_prediksi_model(): void
    {
        $korpus = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus',
            'analysis_type' => 'sentiment',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => ['Pelayanan di kantor itu sangat memuaskan'],
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $korpus->id,
            'predictions' => [[
                'text' => 'Pelayanan di kantor itu sangat memuaskan',
                'sentiment' => 'positive',
                'confidence' => 0.9712,
            ]],
        ]);

        $sampling = app(GoldenSamplingService::class);
        $hasil = $sampling->sample(['analysis_ids' => [$korpus->id], 'size' => 1, 'seed' => 42]);
        $dataset = $sampling->store($hasil['rows'], $hasil['plan'], ['name' => 'Sampel', 'split' => 'test']);

        // Bawaannya tanpa prediksi: anotator yang melihat tebakan model
        // cenderung menyetujuinya.
        $this->assertNotContains('model_prediction', $sampling->annotationHeader());
        $this->assertCount(7, $sampling->annotationRows($dataset)[0]);

        $header = $sampling->annotationHeader(true);
        $baris = $sampling->annotationRows($dataset, true)[0];

        $this->assertSame('model_prediction', $header[7]);
        $this->assertSame('model_confidence', $header[8]);
        $this->assertSame('positive', $baris[7]);
        $this->assertEquals(0.9712, $baris[8]);

        // Analisis sentimen saja tidak punya data aspek: kosong, bukan `-`.
        // `-` akan terbaca "model memeriksa dan tidak menemukan aspek".
        $this->assertSame('model_aspects', $header[9]);
        $this->assertSame('', $baris[9]);
    }

    public function test_berkas_anotasi_menyertakan_aspek_model_dari_document_aspects(): void
    {
        $teks = [
            'Pelayanannya lambat sekali di kantor itu',
            'Antrean panjang dan petugasnya tidak ramah',
            'Semoga semuanya sehat selalu ya bapak ibu',
            'Komentar yang batch aspeknya gagal diproses',
        ];

        $korpus = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus aspek',
            'analysis_type' => 'combined',
            'input_type' => 'manual',
            'status' => 'completed',
            'raw_data' => $teks,
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $korpus->id,
            // Dikunci indeks raw_data. Indeks 3 sengaja tidak ada (batch gagal),
            // dan kedua bentuk elemen yang masih beredar ikut diuji.
            'document_aspects' => [
                0 => ['Pelayanannya', 'pelayanannya'],
                1 => [['aspect' => 'antrean'], ['aspect' => 'petugasnya']],
                2 => [],
            ],
        ]);

        $sampling = app(GoldenSamplingService::class);
        $hasil = $sampling->sample(['analysis_ids' => [$korpus->id], 'size' => 4, 'seed' => 42]);
        $dataset = $sampling->store($hasil['rows'], $hasil['plan'], ['name' => 'Sampel', 'split' => 'test']);

        $header = $sampling->annotationHeader(true);
        $kolom = array_search('model_aspects', $header, true);
        $this->assertNotFalse($kolom);

        $perTeks = [];
        foreach ($sampling->annotationRows($dataset, true) as $baris) {
            $perTeks[$baris[1]] = $baris[$kolom];
        }

        // Bentuk permukaan, kembar beda huruf dibuang.
        $this->assertSame('Pelayanannya', $perTeks[$teks[0]]);
        $this->assertSame('antrean;petugasnya', $perTeks[$teks[1]]);
        $this->assertSame('-', $perTeks[$teks[2]]);
        $this->assertSame('', $perTeks[$teks[3]]);

        // Tanpa prediksi, kolom itu tidak ada sama sekali.
        $this->assertNotContains('model_aspects', $sampling->annotationHeader());
    }

    public function test_impor_anotasi_mengabaikan_kolom_prediksi_dan_jejak_aplikasi(): void
    {
        $dataset = $this->datasetSampel();
        $sampling = app(GoldenSamplingService::class);
        $pertama = $dataset->items()->orderBy('id')->first();

        // Persis bentuk ekspor aplikasi anotasi dari berkas "+ Prediksi Model".
        // Aspek model berbeda dari pilihan anotator; yang masuk label emas
        // harus pilihan anotator.
        $isi = [
            'id,text,label,label_2,aspects,segment,note,model_prediction,model_confidence,model_aspects,changed_after_reveal,aspects_changed_after_reveal,annotator',
            $pertama->id.',"'.$pertama->text.'",negatif,,pelayanan,,,positive,0.91,antrean;petugas,1,1,Anotator A',
        ];

        $hasil = $sampling->applyAnnotations($dataset, $this->csv($isi));

        $this->assertSame(1, $hasil['filled']);
        $this->assertSame(1, $hasil['aspects_filled']);
        $this->assertSame(0, $hasil['unmatched']);

        $segar = $pertama->fresh();
        $this->assertSame('negative', $segar->gold_sentiment);
        $this->assertSame(['pelayanan'], $segar->gold_aspects);
    }

    public function test_baris_tanpa_label_bisa_dibuang_lalu_dataset_bisa_dibekukan(): void
    {
        $this->korpus(20);

        $sampling = app(GoldenSamplingService::class);
        $hasil = $sampling->sample(['analysis_ids' => [1], 'size' => 4, 'seed' => 42]);
        $dataset = $sampling->store($hasil['rows'], $hasil['plan'], ['name' => 'Sampel', 'split' => 'test']);

        // Dua baris berhasil dianotasi, dua ditandai "tidak jelas" oleh anotator
        // dan pulang tanpa label.
        $dataset->items()->orderBy('id')->limit(2)->update(['gold_sentiment' => 'positive']);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.evaluation.drop-unlabeled', $dataset->id))
            ->assertRedirect();

        $segar = $dataset->fresh();
        $this->assertSame(2, $segar->item_count);
        $this->assertSame(0, $segar->unlabeledCount());

        // Jumlahnya dicatat: berapa persen korpus yang bahkan manusia tidak bisa
        // memutuskannya adalah temuan yang harus dilaporkan, bukan disembunyikan.
        $this->assertSame(2, $segar->sampling_plan['dropped_unlabeled']);

        $this->assertTrue(app(GoldenDatasetService::class)->freeze($segar)->isFrozen());
    }

    public function test_halaman_pengambilan_sampel_hanya_untuk_admin(): void
    {
        $this->korpus(20);

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.evaluation.sampling'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.sampling'))
            ->assertOk()
            ->assertSee('Acak, bukan berdasarkan keyakinan model', false);
    }

    public function test_pengambilan_sampel_lewat_halaman_admin(): void
    {
        $this->korpus(40, kolom: 'lembaga', pembagian: ['Lembaga A' => 20, 'Lembaga B' => 20]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.evaluation.sample'), [
                'analysis_ids' => [1],
                'name' => 'Hold-out studi kasus',
                'ukuran_mode' => 'jumlah',
                'size' => 20,
                'seed' => 42,
                'stratify_by' => 'lembaga',
                'min_length' => 20,
                'min_per_stratum' => 0,
            ])
            ->assertRedirect();

        $dataset = EvaluationDataset::firstWhere('name', 'Hold-out studi kasus');

        $this->assertNotNull($dataset);
        $this->assertSame(20, $dataset->item_count);
        $this->assertSame(20, $dataset->unlabeledCount());
        $this->assertSame('sampling', $dataset->source);

        // Halaman detail harus merender blok rencana pengambilan dan kemajuan
        // anotasi - keduanya cabang yang tidak pernah dilalui dataset impor.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.evaluation.show', $dataset->id))
            ->assertOk()
            ->assertSee('Rencana pengambilan sampel')
            ->assertSee('Unduh Berkas Anotasi');
    }

    // ------------------------------------------------------------------ util

    private function korpus(int $jumlah, ?string $kolom = null, array $pembagian = []): TextAnalysis
    {
        $teks = [];
        $segmen = [];
        $urutanSegmen = [];

        foreach ($pembagian as $nama => $banyak) {
            for ($i = 0; $i < $banyak; $i++) {
                $urutanSegmen[] = $nama;
            }
        }

        for ($i = 0; $i < $jumlah; $i++) {
            $teks[] = "Komentar warga nomor {$i} tentang pelayanan lembaga tersebut";

            if ($kolom) {
                $segmen[$i] = [$kolom => $urutanSegmen[$i] ?? 'Lainnya'];
            }
        }

        return TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Korpus studi kasus',
            'analysis_type' => 'sentiment',
            'input_type' => 'csv',
            'status' => 'completed',
            'raw_data' => $teks,
            'segment_data' => $kolom ? ['columns' => [$kolom], 'rows' => $segmen] : null,
        ]);
    }

    private function datasetSampel(): EvaluationDataset
    {
        $this->korpus(20);

        $sampling = app(GoldenSamplingService::class);
        $hasil = $sampling->sample(['analysis_ids' => [1], 'size' => 10, 'seed' => 42]);

        return $sampling->store($hasil['rows'], $hasil['plan'], ['name' => 'Sampel uji', 'split' => 'test']);
    }

    private function csv(array $baris): string
    {
        $path = tempnam(sys_get_temp_dir(), 'anotasi').'.csv';
        file_put_contents($path, implode("\n", $baris));

        return $path;
    }
}
