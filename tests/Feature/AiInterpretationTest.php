<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Interpretasi AI adalah satu-satunya bagian sistem yang dulu memakai Guzzle
 * mentah, sehingga tidak bisa di-fake dan tidak punya tes sama sekali.
 * Setelah pindah ke facade Http, seluruh jalurnya bisa diuji tanpa memanggil
 * Gemini sungguhan.
 */
class AiInterpretationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.key', 'kunci-uji');
        config()->set('services.gemini.model', 'gemini-3.5-flash');
    }

    /** Balasan Gemini palsu: teksnya berisi JSON sesuai response_schema. */
    private function fakeGemini(array $payload, int $status = 200): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => json_encode($payload)]]]],
                ],
            ], $status),
        ]);
    }

    private function analysis(array $overrides = []): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Opini warga soal tarif',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => ['tarifnya mahal', 'pelayanannya bagus'],
            'total_records' => 2,
            'status' => 'completed',
        ]);

        AnalysisResult::create(array_merge([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                ['original_text' => 'tarifnya mahal', 'sentiment' => 'negative', 'confidence' => 0.9],
                ['original_text' => 'pelayanannya bagus', 'sentiment' => 'positive', 'confidence' => 0.8],
            ],
            'sentiment_distribution' => ['positive' => 50.0, 'neutral' => 0.0, 'negative' => 50.0],
            'aspect_results' => [
                ['aspect' => 'tarif', 'count' => 1, 'sentiments' => ['positive' => 0, 'neutral' => 0, 'negative' => 100]],
            ],
            'topic_results' => [
                'topics' => [
                    ['topic_id' => 0, 'words' => ['tarif', 'mahal', 'naik'], 'proportion' => 0.6],
                    ['topic_id' => 1, 'words' => ['pelayanan', 'ramah'], 'proportion' => 0.4],
                ],
            ],
            'summary' => 'Ringkasan sistem.',
        ], $overrides));

        return $analysis->fresh('result');
    }

    // ----------------------------------------------------------- narasi umum

    public function test_narasi_sentimen_dibangkitkan_dan_disimpan(): void
    {
        $analysis = $this->analysis();

        $this->fakeGemini([
            'narrative' => 'Opini terbelah rata antara positif dan negatif.',
            'highlights' => ['Setengah teks bernada negatif.', 'Tidak ada teks netral.'],
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.narrative', 'Opini terbelah rata antara positif dan negatif.')
            ->assertJsonPath('data.model', 'gemini-3.5-flash')
            ->assertJsonPath('data.prompt_version', LlmService::PROMPT_VERSION);

        $tersimpan = $analysis->result->fresh()->ai_interpretations;

        $this->assertSame(
            'Opini terbelah rata antara positif dan negatif.',
            $tersimpan['sentiment']['narrative']
        );
        $this->assertArrayHasKey('generated_at', $tersimpan['sentiment']);
    }

    public function test_narasi_yang_sudah_ada_dipakai_ulang_tanpa_memanggil_api(): void
    {
        $analysis = $this->analysis();
        $analysis->result->update([
            'ai_interpretations' => [
                'sentiment' => ['type' => 'narrative', 'narrative' => 'Narasi lama.', 'model' => 'gemini-3.5-flash'],
            ],
        ]);

        Http::fake();

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertOk()
            ->assertJsonPath('data.narrative', 'Narasi lama.');

        // Kuota Gemini tidak boleh terpakai untuk narasi yang sudah tersimpan.
        Http::assertNothingSent();
    }

    public function test_regenerate_menimpa_narasi_lama(): void
    {
        $analysis = $this->analysis();
        $analysis->result->update([
            'ai_interpretations' => ['sentiment' => ['narrative' => 'Narasi lama.']],
        ]);

        $this->fakeGemini(['narrative' => 'Narasi baru.', 'highlights' => []]);

        $this->actingAs($analysis->user)
            ->postJson(
                route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']),
                ['regenerate' => 1]
            )
            ->assertOk()
            ->assertJsonPath('data.narrative', 'Narasi baru.');

        $this->assertSame('Narasi baru.', $analysis->result->fresh()->ai_interpretations['sentiment']['narrative']);
    }

    public function test_angka_hasil_analisis_ikut_dikirim_ke_model(): void
    {
        $analysis = $this->analysis();
        $this->fakeGemini(['narrative' => 'x', 'highlights' => []]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertOk();

        Http::assertSent(function ($request) {
            $prompt = $request['contents'][0]['parts'][0]['text'];

            // Model merangkai kalimat dari angka yang dikirim; ia tidak
            // diminta menghitung apa pun sendiri.
            return str_contains($prompt, 'distribusi_persen')
                && str_contains($prompt, 'Opini warga soal tarif')
                && str_contains($prompt, 'Jangan menghitung ulang');
        });
    }

    public function test_permintaan_memakai_response_schema(): void
    {
        $analysis = $this->analysis();
        $this->fakeGemini(['narrative' => 'x', 'highlights' => []]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertOk();

        Http::assertSent(function ($request) {
            $config = $request['generationConfig'];

            return isset($config['response_schema']['properties']['narrative'])
                && $config['response_mime_type'] === 'application/json';
        });
    }

    // ------------------------------------------------------------ label topik

    public function test_label_topik_disimpan_di_dua_tempat(): void
    {
        $analysis = $this->analysis();

        $this->fakeGemini([
            'topics' => [
                ['topic_id' => 0, 'label' => 'Kenaikan Tarif', 'description' => 'Keluhan soal tarif yang naik.'],
                ['topic_id' => 1, 'label' => 'Mutu Pelayanan', 'description' => 'Tanggapan atas pelayanan petugas.'],
            ],
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'topic']))
            ->assertOk()
            ->assertJsonPath('data.topics.0.label', 'Kenaikan Tarif');

        $result = $analysis->result->fresh();

        // topic_results tetap diisi supaya heatmap asosiasi dan export PDF -
        // yang membacanya dari sana - ikut memakai label yang sama.
        $this->assertSame('Kenaikan Tarif', $result->topic_results['interpretation'][0]['label']);
        $this->assertSame('Mutu Pelayanan', $result->ai_interpretations['topic']['topics'][1]['label']);
    }

    public function test_topic_id_karangan_dari_model_diabaikan(): void
    {
        $analysis = $this->analysis();

        $this->fakeGemini([
            'topics' => [
                ['topic_id' => 0, 'label' => 'Kenaikan Tarif', 'description' => 'Sah.'],
                ['topic_id' => 99, 'label' => 'Topik Karangan', 'description' => 'Tidak ada topik 99.'],
            ],
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'topic']))
            ->assertOk();

        $interpretation = $analysis->result->fresh()->topic_results['interpretation'];

        $this->assertArrayHasKey(0, $interpretation);
        $this->assertArrayNotHasKey(99, $interpretation);
    }

    // ------------------------------------------------------------- kegagalan

    public function test_kuota_habis_memberi_pesan_yang_bisa_ditindaklanjuti(): void
    {
        $analysis = $this->analysis();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Kuota AI harian sudah habis. Coba lagi besok atau gunakan API key lain.']);

        // Kegagalan tidak boleh menyisakan narasi separuh jadi.
        $this->assertNull($analysis->result->fresh()->ai_interpretations);
    }

    public function test_api_key_kosong_ditolak_sebelum_memanggil_jaringan(): void
    {
        config()->set('services.gemini.key', null);
        $analysis = $this->analysis();

        Http::fake();

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'GEMINI_API_KEY belum diisi pada .env.']);

        Http::assertNothingSent();
    }

    public function test_jawaban_bukan_json_ditolak_dengan_rapi(): void
    {
        $analysis = $this->analysis();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ini bukan json']]]]],
            ]),
        ]);

        $this->actingAs($analysis->user)
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Jawaban AI tidak dapat dibaca. Coba bangkitkan ulang.']);
    }

    public function test_bagian_yang_tidak_dikenal_menghasilkan_404(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->postJson('/analysis/'.$analysis->id.'/interpret/entahapa')
            ->assertNotFound();
    }

    public function test_analisis_milik_pengguna_lain_tidak_bisa_diinterpretasi(): void
    {
        $analysis = $this->analysis();

        $this->actingAs(User::factory()->create())
            ->postJson(route('analysis.interpret', ['id' => $analysis->id, 'section' => 'sentiment']))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- tampilan

    public function test_halaman_hasil_menampilkan_tombol_dan_narasi_tersimpan(): void
    {
        $analysis = $this->analysis();
        $analysis->result->update([
            'ai_interpretations' => [
                'sentiment' => [
                    'type' => 'narrative',
                    'narrative' => 'Opini terbelah rata.',
                    'highlights' => ['Setengah negatif.'],
                    'model' => 'gemini-3.5-flash',
                    'generated_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Opini terbelah rata.', $html);
        $this->assertStringContainsString('Setengah negatif.', $html);
        // Pembaca harus tahu kalimatnya ditulis mesin.
        $this->assertStringContainsString('Ditulis oleh AI', $html);
        $this->assertStringContainsString('Bangkitkan Ulang', $html);
        // Bagian yang belum dibangkitkan tetap menawarkan tombolnya.
        $this->assertStringContainsString('Jelaskan dengan AI', $html);
    }

    public function test_export_pdf_memuat_narasi_beserta_asal_usulnya(): void
    {
        $analysis = $this->analysis();
        $analysis->result->update([
            'ai_interpretations' => [
                'overview' => [
                    'narrative' => 'Gambaran menyeluruh hasil analisis.',
                    'highlights' => ['Sentimen terbelah rata.'],
                    'model' => 'gemini-3.5-flash',
                    'generated_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $html = view('analysis.export-pdf', [
            'analysis' => $analysis->fresh('result'),
            'result' => $analysis->result->fresh(),
            'predictions' => [],
        ])->render();

        $this->assertStringContainsString('Gambaran menyeluruh hasil analisis.', $html);
        $this->assertStringContainsString('Sentimen terbelah rata.', $html);
        // Kutipan di naskah harus membawa nama model dan tanggalnya.
        $this->assertStringContainsString('gemini-3.5-flash', $html);
        $this->assertStringContainsString('bukan dari AI', $html);
    }

    public function test_halaman_hasil_tetap_utuh_tanpa_narasi_ai(): void
    {
        $analysis = $this->analysis();

        $this->actingAs($analysis->user)
            ->get(route('analysis.show', $analysis->id))
            ->assertOk()
            // Ringkasan template bawaan tetap tampil, jadi halaman tidak
            // bergantung pada tersedianya kuota AI.
            ->assertSee('Ringkasan sistem.')
            ->assertSee('Jelaskan dengan AI');
    }
}
