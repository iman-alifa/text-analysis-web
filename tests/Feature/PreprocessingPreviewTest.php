<?php

namespace Tests\Feature;

use App\Models\PreprocessingConfig;
use App\Models\User;
use App\Services\PreprocessingConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pratinjau preprocessing harus mengirim `task` sesuai jenis analisis.
 *
 * Tanpa itu pratinjau berbohong: pengguna menyalakan stemming, melihat teks
 * ter-stem, lalu analisis sentimen diam-diam mematikannya.
 */
class PreprocessingPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp_api.url' => 'http://nlp.test']);
    }

    private function fakePreprocess(): void
    {
        Http::fake([
            '*/api/preprocess' => Http::response([
                'status' => 'success',
                'preprocessed' => ['pelayanan tidak bagus'],
                'original_count' => 1,
                'processed_count' => 1,
                'task' => 'transformer',
                'applied_policy' => ['stemming' => false, 'remove_stopwords' => false],
            ], 200),
        ]);
    }

    private function preview(array $overrides = [])
    {
        return $this->actingAs(User::factory()->create())
                    ->postJson(route('analysis.preprocess-preview'), array_merge([
                        'texts' => ['Pelayanannya tidak bagus sama sekali'],
                        'analysis_type' => 'sentiment',
                    ], $overrides));
    }

    public function test_mengirim_task_transformer_untuk_analisis_sentimen(): void
    {
        $this->fakePreprocess();

        $this->preview(['analysis_type' => 'sentiment'])
             ->assertOk()
             ->assertJsonPath('task', 'transformer');

        Http::assertSent(fn ($request) => $request['task'] === 'transformer');
    }

    public function test_mengirim_task_sesuai_tiap_jenis_analisis(): void
    {
        $resolver = new PreprocessingConfigResolver();

        $this->assertSame('transformer', $resolver->taskForAnalysisType('sentiment'));
        $this->assertSame('bag_of_words', $resolver->taskForAnalysisType('topic'));
        $this->assertSame('span', $resolver->taskForAnalysisType('aspect'));
        // Gabungan menjalankan tiga modul dengan kebijakan berbeda-beda
        $this->assertNull($resolver->taskForAnalysisType('combined'));
    }

    public function test_menjelaskan_opsi_yang_ditimpa_kebijakan_modul(): void
    {
        $this->fakePreprocess();

        $this->preview(['analysis_type' => 'sentiment'])
             ->assertOk()
             ->assertJsonPath('applied_policy.stemming', false)
             ->assertJsonFragment(['notice' => 'Stemming dan penghapusan stopword dinonaktifkan untuk analisis sentimen karena merusak deteksi negasi.']);
    }

    public function test_memakai_konfigurasi_yang_sama_dengan_yang_dijalankan_job(): void
    {
        $this->fakePreprocess();

        $config = PreprocessingConfig::create([
            'name' => 'Tanpa stemming',
            'description' => 'uji',
            'case_folding' => true,
            'remove_punctuation' => false,
            'remove_numbers' => false,
            'remove_stopwords' => false,
            'stemming' => false,
            'lemmatization' => false,
            'custom_stopwords' => ['nih'],
            'is_default' => false,
        ]);

        $this->preview(['preprocessing_config_id' => $config->id])
             ->assertOk()
             ->assertJsonPath('config_name', 'Tanpa stemming');

        Http::assertSent(function ($request) {
            return $request['config']['stemming'] === false
                && $request['config']['remove_punctuation'] === false
                && in_array('nih', $request['config']['custom_stopwords'], true);
        });
    }

    public function test_menolak_lebih_dari_lima_teks(): void
    {
        $this->preview(['texts' => ['a', 'b', 'c', 'd', 'e', 'f']])
             ->assertStatus(422);
    }

    public function test_memberi_pesan_ramah_saat_nlp_api_mati(): void
    {
        Http::fake(['*/api/preprocess' => Http::response('down', 500)]);

        $this->preview()
             ->assertStatus(503)
             ->assertJsonPath('success', false)
             ->assertJsonPath('message', 'Tidak bisa menghubungi layanan analisis. Pastikan NLP API berjalan.');
    }

    public function test_hanya_untuk_pengguna_yang_login(): void
    {
        $this->postJson(route('analysis.preprocess-preview'), [
            'texts' => ['halo'],
            'analysis_type' => 'sentiment',
        ])->assertUnauthorized();
    }
}
