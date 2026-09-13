<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\FileProcessingService;
use App\Services\SegmentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pemisah membiarkan pengguna melihat hasil per nilai unik sebuah kolom
 * (tanggal, sumber, judul video) tanpa menjalankan analisis terpisah untuk
 * masing-masing. Seluruhnya dihitung ulang dari prediksi yang sudah tersimpan.
 */
class SegmentAnalysisTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ ekstraksi

    private function csv(string $isi): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'seg').'.csv';
        file_put_contents($path, $isi);

        return new UploadedFile($path, 'data.csv', 'text/csv', null, true);
    }

    public function test_kolom_pemisah_diambil_sejajar_dengan_teksnya(): void
    {
        $file = $this->csv("komentar,video,tanggal\nbagus sekali,Video A,2024-01-03\nmahal,Video B,2024-01-04\n");

        $hasil = (new FileProcessingService)->processFileWithConfig($file, [
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
            'segment_columns' => ['video', 'tanggal'],
        ]);

        $this->assertSame(['bagus sekali', 'mahal'], $hasil['texts']);
        $this->assertSame(['video', 'tanggal'], $hasil['segment_columns']);
        $this->assertSame('Video A', $hasil['segments'][0]['video']);
        $this->assertSame('2024-01-04', $hasil['segments'][1]['tanggal']);
    }

    public function test_baris_berteks_kosong_tidak_menggeser_indeks_segmen(): void
    {
        // Baris berteks kosong dilewati saat mengambil teks. Kalau kolom
        // pemisah diambil pada lintasan terpisah, seluruh pengelompokan
        // hasil akan menunjuk kalimat yang salah mulai dari baris kosong itu.
        $file = $this->csv("komentar,video\nbagus,Video A\n,Video B\nmahal,Video C\n");

        $hasil = (new FileProcessingService)->processFileWithConfig($file, [
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
            'segment_columns' => ['video'],
        ]);

        $this->assertSame(['bagus', 'mahal'], $hasil['texts']);
        $this->assertCount(2, $hasil['segments']);
        $this->assertSame('Video A', $hasil['segments'][0]['video']);
        // 'mahal' harus tetap berpasangan dengan Video C, bukan Video B.
        $this->assertSame('Video C', $hasil['segments'][1]['video']);
    }

    public function test_kolom_teks_tidak_bisa_dipakai_sebagai_pemisah(): void
    {
        $file = $this->csv("komentar,video\nbagus,Video A\n");

        $hasil = (new FileProcessingService)->processFileWithConfig($file, [
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
            'segment_columns' => ['komentar', 'video'],
        ]);

        $this->assertSame(['video'], $hasil['segment_columns']);
    }

    public function test_kolom_yang_tidak_ada_diabaikan(): void
    {
        $file = $this->csv("komentar,video\nbagus,Video A\n");

        $hasil = (new FileProcessingService)->processFileWithConfig($file, [
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
            'segment_columns' => ['tidak_ada'],
        ]);

        $this->assertSame([], $hasil['segment_columns']);
    }

    public function test_tanpa_pemisah_hasilnya_tetap_seperti_dulu(): void
    {
        $file = $this->csv("komentar\nbagus\nmahal\n");

        $hasil = (new FileProcessingService)->processFileWithConfig($file, [
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
        ]);

        $this->assertSame(['bagus', 'mahal'], $hasil['texts']);
        $this->assertSame(2, $hasil['total']);
        $this->assertSame([], $hasil['segments']);
    }

    // --------------------------------------------------------- unggah penuh

    public function test_pemisah_tersimpan_saat_analisis_dibuat(): void
    {
        Queue::fake();
        Http::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('analysis.store'), [
            'title' => 'Komentar per video',
            'input_type' => 'file',
            'analysis_type' => 'sentiment',
            'file' => $this->csv("komentar,video\nbagus,Video A\nmahal,Video B\n"),
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
            'segment_columns' => ['video'],
        ])->assertRedirect();

        $analysis = TextAnalysis::latest('id')->first();

        $this->assertSame(['video'], $analysis->segment_data['columns']);
        $this->assertSame('Video A', $analysis->segment_data['rows'][0]['video']);
    }

    public function test_lebih_dari_tiga_kolom_ditolak(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('analysis.store'), [
            'title' => 'Terlalu banyak pemisah',
            'input_type' => 'file',
            'analysis_type' => 'sentiment',
            'file' => $this->csv("komentar,a,b,c,d\nbagus,1,2,3,4\n"),
            'file_has_header' => 'on',
            'text_column_name' => 'komentar',
            'segment_columns' => ['a', 'b', 'c', 'd'],
        ])->assertSessionHasErrors('segment_columns');
    }

    // ------------------------------------------------------------- rincian

    private function analysis(): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Komentar per video',
            'input_type' => 'csv',
            'analysis_type' => 'combined',
            'raw_data' => ['a', 'b', 'c', 'd'],
            'segment_data' => [
                'columns' => ['video'],
                'rows' => [
                    ['video' => 'Video A'],
                    ['video' => 'Video A'],
                    ['video' => 'Video B'],
                    ['video' => ''],
                ],
            ],
            'total_records' => 4,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                // Sengaja tidak berurutan: pada korpus yang diproses per batch
                // urutan prediksi tidak selalu sama dengan urutan teks masukan.
                ['original_index' => 2, 'sentiment' => 'positive', 'aspects' => ['harga']],
                ['original_index' => 0, 'sentiment' => 'negative', 'aspects' => ['harga', 'mutu']],
                ['original_index' => 3, 'sentiment' => 'neutral', 'aspects' => []],
                ['original_index' => 1, 'sentiment' => 'negative', 'aspects' => ['harga']],
            ],
            'sentiment_distribution' => ['positive' => 25.0, 'neutral' => 25.0, 'negative' => 50.0],
        ]);

        return $analysis->fresh('result');
    }

    public function test_rincian_memakai_original_index_bukan_urutan_larik(): void
    {
        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($this->analysis(), 'video');

        $videoA = collect($breakdown['segments'])->firstWhere('label', 'Video A');
        $videoB = collect($breakdown['segments'])->firstWhere('label', 'Video B');

        // Indeks 0 dan 1 keduanya negatif dan keduanya milik Video A.
        $this->assertSame(2, $videoA['count']);
        $this->assertSame(100.0, $videoA['distribution']['negative']);

        // Indeks 2 positif, milik Video B.
        $this->assertSame(1, $videoB['count']);
        $this->assertSame(100.0, $videoB['distribution']['positive']);
    }

    public function test_baris_tanpa_nilai_dihitung_terpisah(): void
    {
        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($this->analysis(), 'video');

        // Kalau tidak dilaporkan, jumlah seluruh segmen tampak tidak cocok
        // dengan total analisis.
        $this->assertSame(1, $breakdown['ungrouped']);
        $this->assertSame(2, $breakdown['total_segments']);
    }

    public function test_aspek_teratas_dihitung_per_segmen(): void
    {
        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($this->analysis(), 'video');

        $videoA = collect($breakdown['segments'])->firstWhere('label', 'Video A');

        $this->assertSame('harga', $videoA['top_aspects'][0]['aspect']);
        $this->assertSame(2, $videoA['top_aspects'][0]['count']);
    }

    public function test_topik_dominan_dihitung_per_segmen(): void
    {
        $analysis = $this->analysis();
        $analysis->result->update([
            'topic_results' => [
                'topics' => [
                    ['topic_id' => 0, 'words' => ['harga', 'mahal']],
                    ['topic_id' => 1, 'words' => ['layanan', 'ramah']],
                ],
                // Sejajar dengan original_index, sama seperti document_aspects:
                // 0 dan 1 (Video A) -> topik 0, 2 (Video B) -> topik 1,
                // 3 (tanpa nilai pemisah) -> outlier (-1), tidak dihitung.
                'document_topics' => [0, 0, 1, -1],
            ],
        ]);

        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($analysis->fresh('result'), 'video');

        $videoA = collect($breakdown['segments'])->firstWhere('label', 'Video A');
        $videoB = collect($breakdown['segments'])->firstWhere('label', 'Video B');

        $this->assertSame('Topik #1', $videoA['top_topics'][0]['label']);
        $this->assertSame(2, $videoA['top_topics'][0]['count']);
        $this->assertSame('Topik #2', $videoB['top_topics'][0]['label']);
    }

    public function test_topik_dominan_memakai_label_ai_kalau_ada(): void
    {
        $analysis = $this->analysis();
        $analysis->result->update([
            'topic_results' => [
                'topics' => [['topic_id' => 0, 'words' => ['harga', 'mahal']]],
                'interpretation' => [0 => ['label' => 'Keluhan Harga', 'description' => '...']],
                'document_topics' => [0, 0, 0, 0],
            ],
        ]);

        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($analysis->fresh('result'), 'video');

        $videoA = collect($breakdown['segments'])->firstWhere('label', 'Video A');

        $this->assertSame('Keluhan Harga', $videoA['top_topics'][0]['label']);
    }

    public function test_top_topics_kosong_kalau_analisis_tidak_punya_topic_modeling(): void
    {
        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($this->analysis(), 'video');

        $videoA = collect($breakdown['segments'])->firstWhere('label', 'Video A');

        $this->assertSame([], $videoA['top_topics']);
    }

    public function test_segmen_terbesar_lebih_dulu(): void
    {
        $breakdown = app(SegmentAnalysisService::class)
            ->breakdown($this->analysis(), 'video');

        $this->assertSame('Video A', $breakdown['segments'][0]['label']);
    }

    public function test_kolom_yang_tidak_terdaftar_ditolak(): void
    {
        $this->assertNull(
            app(SegmentAnalysisService::class)->breakdown($this->analysis(), 'kolom_karangan')
        );
    }

    // ------------------------------------------------------------- tampilan

    public function test_rincian_tidak_langsung_ditampilkan(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        // Halaman hasil harus memperlihatkan gambaran keseluruhan lebih dulu:
        // menunya ada, tetapi tabel rinciannya belum dihitung apalagi dirender.
        $this->assertStringContainsString('Rincian per Pemisah', $html);
        $this->assertStringNotContainsString('Video A', $html);

        // Diperiksa lewat caption tabel, bukan lewat teks ajakannya: teks
        // ajakan juga ada di dalam JavaScript sehingga selalu ditemukan dan
        // tidak membuktikan apa pun.
        $this->assertStringNotContainsString('Rincian hasil analisis menurut kolom', $html);
    }

    public function test_rincian_muncul_saat_kolom_diminta_lewat_url(): void
    {
        $analysis = $this->analysis();

        // Parameter kueri, bukan hanya AJAX: pilihannya bisa ditandai dan
        // halaman tetap berfungsi tanpa JavaScript.
        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id).'?segment=video')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Video A', $html);
        $this->assertStringContainsString('baris tanpa nilai', $html);
        $this->assertStringContainsString('Rincian hasil analisis menurut kolom', $html);
    }

    public function test_kolom_karangan_pada_url_diabaikan(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id).'?segment=karangan')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Rincian hasil analisis menurut kolom', $html);
    }

    public function test_endpoint_mengembalikan_html_dari_partial_yang_sama(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->getJson(route('analysis.segments', $analysis->id).'?column=video')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('column', 'video')
            ->assertJsonFragment(['column' => 'video']);
    }

    public function test_endpoint_menolak_kolom_yang_tidak_ada(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->getJson(route('analysis.segments', $analysis->id).'?column=karangan')
            ->assertNotFound();
    }

    public function test_analisis_milik_orang_lain_tertutup(): void
    {
        $analysis = $this->analysis();

        $this->actingAs(User::factory()->create())
            ->getJson(route('analysis.segments', $analysis->id).'?column=video')
            ->assertNotFound();
    }

    public function test_analisis_tanpa_pemisah_tidak_menampilkan_blok_rincian(): void
    {
        $analysis = $this->analysis();
        $analysis->update(['segment_data' => null]);

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->assertDontSee('Rincian per Pemisah');
    }

    // ------------------------------------------------------------- narasi AI

    public function test_narasi_ai_disimpan_per_kolom(): void
    {
        config()->set('services.gemini.key', 'kunci-uji');
        $analysis = $this->analysis();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [[
                    'text' => json_encode(['narrative' => 'Video A paling negatif.', 'highlights' => []]),
                ]]]]],
            ]),
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'segment']).'?column=video')
            ->assertOk()
            ->assertJsonPath('data.narrative', 'Video A paling negatif.');

        // Kunci menyertakan nama kolom, supaya berpindah kolom tidak
        // menampilkan narasi tentang kolom yang lain.
        $this->assertArrayHasKey('segment:video', $analysis->result->fresh()->ai_interpretations);
    }

    public function test_narasi_segmen_menerima_pembanding_keseluruhan(): void
    {
        config()->set('services.gemini.key', 'kunci-uji');
        $analysis = $this->analysis();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [[
                    'text' => json_encode(['narrative' => 'x', 'highlights' => []]),
                ]]]]],
            ]),
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'segment']).'?column=video')
            ->assertOk();

        Http::assertSent(function ($request) {
            $prompt = $request['contents'][0]['parts'][0]['text'];
            $aturan = $request['systemInstruction']['parts'][0]['text'];

            // Tanpa pembanding keseluruhan, model hanya bisa membacakan ulang
            // angka tiap segmen, bukan menunjuk mana yang menyimpang. Datanya
            // di giliran user, instruksi tugasnya ada di systemInstruction.
            return str_contains($prompt, 'distribusi_keseluruhan_persen')
                && str_contains($aturan, 'tidak cukup kuat untuk disimpulkan');
        });
    }

    public function test_narasi_segmen_tanpa_kolom_ditolak(): void
    {
        config()->set('services.gemini.key', 'kunci-uji');
        $analysis = $this->analysis();

        Http::fake();

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'segment']))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Kolom pemisah belum dipilih.']);

        Http::assertNothingSent();
    }
}
