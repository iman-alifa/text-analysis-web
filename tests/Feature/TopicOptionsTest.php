<?php

namespace Tests\Feature;

use App\Jobs\ProcessTextAnalysis;
use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\NLPApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * num_topics = 0 berarti "cari sendiri jumlah topik terbaik" di sisi API,
 * dan 1 ditolak API. Keduanya harus ditangani sebelum permintaan dikirim.
 */
class TopicOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $overrides = [])
    {
        return $this->actingAs(User::factory()->create())->post(route('analysis.store'), array_merge([
            'title' => 'Analisis topik',
            'input_type' => 'manual',
            'analysis_type' => 'topic',
            'manual_text' => "baris pertama\nbaris kedua\nbaris ketiga",
        ], $overrides));
    }

    public function test_mode_otomatis_tersimpan_bukan_dibuang_karena_bernilai_nol(): void
    {
        Queue::fake();

        $this->submit(['num_topics' => 0])->assertRedirect();

        // Pengecekan truthy yang lama membuang 0 diam-diam lalu job memakai 5
        $this->assertSame(0, TextAnalysis::firstOrFail()->metadata['num_topics']);
    }

    public function test_jumlah_topik_satu_ditolak(): void
    {
        Queue::fake();

        $this->submit(['num_topics' => 1])->assertSessionHasErrors('num_topics');

        $this->assertSame(0, TextAnalysis::count());
    }

    public function test_jumlah_topik_di_atas_dua_puluh_ditolak(): void
    {
        Queue::fake();

        $this->submit(['num_topics' => 25])->assertSessionHasErrors('num_topics');
    }

    public function test_angka_yang_dipilih_tersimpan_apa_adanya(): void
    {
        Queue::fake();

        $this->submit(['num_topics' => 14])->assertRedirect();

        $this->assertSame(14, TextAnalysis::firstOrFail()->metadata['num_topics']);
    }

    /**
     * Formulir menawarkan jumlah topik untuk analisis gabungan juga, tetapi
     * nilainya dulu tidak pernah ikut dikirim sehingga API memakai bawaannya (5).
     */
    public function test_jumlah_topik_ikut_terkirim_pada_analisis_gabungan(): void
    {
        config(['services.nlp_api.url' => 'http://nlp.test', 'services.nlp_api.batch_size' => 50]);

        Http::fake([
            '*/api/warmup' => Http::response(['status' => 'success', 'models' => []], 200),
            '*/api/analyze/combined' => Http::response([
                'status' => 'success',
                'results' => [
                    'sentiment' => ['predictions' => [], 'distribution' => [], 'metrics' => []],
                    'aspect' => ['aspect_sentiments' => [], 'document_aspects' => []],
                    'topic' => ['topics' => [], 'document_topics' => []],
                    'association' => null,
                ],
            ], 200),
        ]);

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Gabungan',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => ['a', 'b'],
            'total_records' => 2,
            'status' => 'pending',
            'metadata' => ['num_topics' => 12],
        ]);

        (new ProcessTextAnalysis($analysis))->handle(new NLPApiService());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/analyze/combined')
                && $request['num_topics'] === 12;
        });
    }

    public function test_mode_otomatis_ikut_terkirim_pada_analisis_gabungan(): void
    {
        config(['services.nlp_api.url' => 'http://nlp.test', 'services.nlp_api.batch_size' => 50]);

        Http::fake([
            '*/api/warmup' => Http::response(['status' => 'success', 'models' => []], 200),
            '*/api/analyze/combined' => Http::response([
                'status' => 'success',
                'results' => [
                    'sentiment' => ['predictions' => [], 'distribution' => [], 'metrics' => []],
                    'aspect' => ['aspect_sentiments' => [], 'document_aspects' => []],
                    'topic' => ['topics' => [], 'document_topics' => []],
                ],
            ], 200),
        ]);

        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Gabungan otomatis',
            'input_type' => 'manual',
            'analysis_type' => 'combined',
            'raw_data' => ['a', 'b'],
            'total_records' => 2,
            'status' => 'pending',
            'metadata' => ['num_topics' => 0],
        ]);

        (new ProcessTextAnalysis($analysis))->handle(new NLPApiService());

        // 0 bernilai falsy - gampang hilang kalau dicek dengan ?: alih-alih ??
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/analyze/combined')
            && $request['num_topics'] === 0);
    }

    public function test_formulir_menawarkan_mode_otomatis_dan_tidak_pernah_mengirim_satu(): void
    {
        $html = $this->actingAs(User::factory()->create())
                     ->get(route('analysis.create'))
                     ->assertOk()
                     ->getContent();

        $this->assertStringContainsString('value="0"', $html);
        $this->assertStringContainsString('Otomatis', $html);
        $this->assertStringNotContainsString('<option value="1">', $html);
        // rentang penuh yang diterima API
        $this->assertStringContainsString('<option value="20">', $html);
        // petunjuk lama yang terbukti keliru secara empiris
        $this->assertStringNotContainsString('Rekomendasi: 3-7 topik', $html);
    }
}
