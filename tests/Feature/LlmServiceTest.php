<?php

namespace Tests\Feature;

use App\Exceptions\LlmException;
use App\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LlmService dulu satu-satunya bagian sistem tanpa tes sama sekali (memakai
 * Guzzle mentah, tidak bisa di-fake). Berkas ini menguji langsung di tingkat
 * layanan - bentuk permintaan ke Gemini dan perilaku daftar model cadangan -
 * terpisah dari AiInterpretationTest yang menguji jalur pengguna penuh.
 */
class LlmServiceTest extends TestCase
{
    use RefreshDatabase;

    private $schema = [
        'type' => 'object',
        'properties' => ['narrative' => ['type' => 'string']],
        'required' => ['narrative'],
    ];

    private function sukses(string $teks = 'ok'): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode(['narrative' => $teks])]]]],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gemini.key', 'kunci-uji');
        config()->set('services.gemini.retry', 0);
    }

    public function test_daftar_model_dipisah_koma_dicoba_berurutan(): void
    {
        config()->set('services.gemini.model', 'model-a,model-b');

        Http::fake([
            '*model-a:generateContent*' => Http::response(['error' => 'unavailable'], 503),
            '*model-b:generateContent*' => Http::response($this->sukses()),
        ]);

        $llm = app(LlmService::class);
        $hasil = $llm->generate('halo', $this->schema);

        $this->assertSame('ok', $hasil['narrative']);

        // Model yang benar-benar menjawab tersimpan di provenance, bukan
        // selalu kandidat pertama - narasinya harus tetap bisa disebut model
        // persisnya walau daftar cadangan dipakai.
        $this->assertSame('model-b', $llm->provenance()['model']);

        Http::assertSentCount(2);
    }

    public function test_model_kedua_tidak_dicoba_kalau_model_pertama_berhasil(): void
    {
        config()->set('services.gemini.model', 'model-a,model-b');

        Http::fake([
            '*generateContent*' => Http::response($this->sukses()),
        ]);

        app(LlmService::class)->generate('halo', $this->schema);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'model-a'));
    }

    public function test_semua_model_gagal_melempar_pesan_yang_bisa_ditindaklanjuti(): void
    {
        config()->set('services.gemini.model', 'model-a,model-b');

        Http::fake([
            '*generateContent*' => Http::response(['error' => 'unavailable'], 503),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('Layanan AI sedang bermasalah. Coba lagi beberapa menit lagi.');

        app(LlmService::class)->generate('halo', $this->schema);
    }

    public function test_kegagalan_404_menyebut_model_yang_benar_benar_dicoba_terakhir(): void
    {
        config()->set('services.gemini.model', 'model-a,model-b');

        Http::fake([
            '*generateContent*' => Http::response(['error' => 'not found'], 404),
        ]);

        try {
            app(LlmService::class)->generate('halo', $this->schema);
            $this->fail('Seharusnya melempar LlmException.');
        } catch (LlmException $e) {
            // Bukan model-a (kandidat pertama) - itu keliru kalau yang gagal
            // paling akhir justru model-b.
            $this->assertStringContainsString('model-b', $e->getMessage());
        }
    }

    public function test_system_instruction_dikirim_terpisah_dari_prompt(): void
    {
        config()->set('services.gemini.model', 'model-a');

        Http::fake(['*generateContent*' => Http::response($this->sukses())]);

        app(LlmService::class)->generate('data tugas', $this->schema, systemInstruction: 'aturan perilaku');

        Http::assertSent(function ($request) {
            return $request['contents'][0]['parts'][0]['text'] === 'data tugas'
                && $request['systemInstruction']['parts'][0]['text'] === 'aturan perilaku';
        });
    }

    public function test_tanpa_system_instruction_field_itu_tidak_disertakan(): void
    {
        config()->set('services.gemini.model', 'model-a');

        Http::fake(['*generateContent*' => Http::response($this->sukses())]);

        app(LlmService::class)->generate('data tugas', $this->schema);

        Http::assertSent(fn ($request) => ! isset($request['systemInstruction']));
    }

    public function test_safety_settings_dilonggarkan_untuk_seluruh_kategori(): void
    {
        config()->set('services.gemini.model', 'model-a');

        Http::fake(['*generateContent*' => Http::response($this->sukses())]);

        app(LlmService::class)->generate('halo', $this->schema);

        Http::assertSent(function ($request) {
            $kategori = collect($request['safetySettings'])->pluck('threshold', 'category');

            // Bawaan Gemini (BLOCK_MEDIUM_AND_ABOVE) pernah memblokir narasi
            // yang sah karena modelnya cuma merangkum kritik warga yang
            // sudah ada di database, bukan mengarang konten berbahaya.
            return $kategori['HARM_CATEGORY_HARASSMENT'] === 'BLOCK_ONLY_HIGH'
                && $kategori['HARM_CATEGORY_HATE_SPEECH'] === 'BLOCK_ONLY_HIGH'
                && $kategori['HARM_CATEGORY_DANGEROUS_CONTENT'] === 'BLOCK_ONLY_HIGH'
                && $kategori['HARM_CATEGORY_SEXUALLY_EXPLICIT'] === 'BLOCK_ONLY_HIGH';
        });
    }
}
