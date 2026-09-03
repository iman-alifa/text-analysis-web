<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Batas yang ditegakkan service NLP diperiksa lebih dulu di Laravel, supaya
 * pengguna mendapat pesan yang bisa dimengerti alih-alih analisis yang gagal
 * di worker dengan HTTP 422 dari API.
 */
class ApiLimitValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.nlp_api.max_texts' => 5,
            'services.nlp_api.max_text_length' => 20,
        ]);
    }

    private function submit(string $manualText)
    {
        return $this->actingAs(User::factory()->create())->post(route('analysis.store'), [
            'title' => 'Uji batas',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'manual_text' => $manualText,
        ]);
    }

    public function test_menolak_jumlah_teks_melebihi_batas(): void
    {
        $this->submit(implode("\n", array_map(fn ($i) => "baris {$i}", range(1, 6))))
            ->assertSessionHasErrors('manual_text');

        $this->assertSame(0, TextAnalysis::count());
    }

    public function test_menyebut_baris_yang_terlalu_panjang(): void
    {
        $response = $this->submit("pendek\n".str_repeat('a', 25));

        $response->assertSessionHasErrors('manual_text');

        $errors = session('errors')->get('manual_text');
        $this->assertStringContainsString('baris ke-2', $errors[0]);
        $this->assertStringContainsString('25 karakter', $errors[0]);
    }

    public function test_meloloskan_data_dalam_batas(): void
    {
        $this->submit("baris satu\nbaris dua")->assertRedirect();

        $this->assertSame(1, TextAnalysis::count());
    }

    public function test_batas_dibaca_dari_config_bukan_angka_tetap(): void
    {
        config(['services.nlp_api.max_texts' => 2]);

        $this->submit("a\nb\nc")->assertSessionHasErrors('manual_text');

        config(['services.nlp_api.max_texts' => 10]);

        $this->submit("a\nb\nc")->assertRedirect();
    }
}
