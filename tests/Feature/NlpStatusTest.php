<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bobot model dimuat malas, sehingga kesiapannya perlu terlihat sebelum
 * pengguna memulai analisis besar.
 */
class NlpStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp_api.url' => 'http://nlp.test']);
    }

    private function healthResponse(array $weights): array
    {
        return [
            'status' => 'healthy',
            'message' => 'ok',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString(),
            'weights_loaded' => $weights,
            'sentiment_source' => 'crypter70/IndoBERT-Sentiment-Analysis',
            'sentiment_base' => 'indobenchmark/indobert-base-p1',
            'sentiment_temperature' => 2.7748,
            'sentiment_review_threshold' => 0.94,
        ];
    }

    public function test_melaporkan_kesiapan_tiap_model(): void
    {
        Http::fake(['*/health' => Http::response($this->healthResponse([
            'sentiment' => true, 'aspect' => true, 'topic' => false,
        ]), 200)]);

        $this->actingAs(User::factory()->create())
             ->getJson(route('analysis.nlp-status'))
             ->assertOk()
             ->assertJsonPath('all_ready', false)
             ->assertJsonPath('weights_loaded.topic', false)
             ->assertJsonPath('model.sentiment_source', 'crypter70/IndoBERT-Sentiment-Analysis')
             ->assertJsonPath('model.sentiment_review_threshold', 0.94);
    }

    public function test_semua_siap_saat_seluruh_bobot_termuat(): void
    {
        Http::fake(['*/health' => Http::response($this->healthResponse([
            'sentiment' => true, 'aspect' => true, 'topic' => true,
        ]), 200)]);

        $this->actingAs(User::factory()->create())
             ->getJson(route('analysis.nlp-status'))
             ->assertOk()
             ->assertJsonPath('all_ready', true);
    }

    public function test_membedakan_layanan_mati_dari_model_belum_siap(): void
    {
        Http::fake(['*/health' => Http::response('', 500)]);

        $this->actingAs(User::factory()->create())
             ->getJson(route('analysis.nlp-status'))
             ->assertStatus(503)
             ->assertJsonPath('success', false);
    }

    public function test_pemanasan_melaporkan_hasilnya(): void
    {
        Http::fake(['*/api/warmup' => Http::response([
            'status' => 'success',
            'total_seconds' => 6.7,
            'models' => [
                ['name' => 'sentiment', 'loaded' => true],
                ['name' => 'aspect', 'loaded' => true],
                ['name' => 'topic', 'loaded' => true],
            ],
        ], 200)]);

        $this->actingAs(User::factory()->create())
             ->postJson(route('analysis.warm-up'))
             ->assertOk()
             ->assertJsonPath('all_ready', true)
             ->assertJsonPath('message', 'Semua model siap.');
    }

    public function test_pemanasan_sebagian_tidak_dianggap_gagal_total(): void
    {
        Http::fake(['*/api/warmup' => Http::response([
            'status' => 'success',
            'models' => [
                ['name' => 'sentiment', 'loaded' => true],
                ['name' => 'topic', 'loaded' => false],
            ],
        ], 200)]);

        $this->actingAs(User::factory()->create())
             ->postJson(route('analysis.warm-up'))
             ->assertOk()
             ->assertJsonPath('all_ready', false)
             ->assertJsonPath('success', true);
    }

    public function test_status_hanya_untuk_pengguna_yang_login(): void
    {
        $this->getJson(route('analysis.nlp-status'))->assertUnauthorized();
    }
}
