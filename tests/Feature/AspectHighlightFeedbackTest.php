<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman koreksi menawarkan cara yang sama dengan halaman anotasi
 * (public/anotasi/index.html) untuk menambah aspek: blok kata pada teksnya,
 * maksimal tiga kata. Markup-nya yang diuji di sini (id yang berpasangan
 * antara teks dan kolom aspek) - perilaku menyorot sendiri hanya bisa
 * diverifikasi lewat peramban sungguhan.
 */
class AspectHighlightFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(string $type = 'aspect'): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Analisis aspek',
            'input_type' => 'manual',
            'analysis_type' => $type,
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => 'completed',
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'Pelayanannya bagus tapi harganya mahal',
            'detected_aspects' => ['pelayanan'],
            'confidence_score' => 0.8,
            'predicted_method' => 'indobert',
        ]);

        return $analysis;
    }

    public function test_teks_dan_kolom_aspek_berpasangan_lewat_id(): void
    {
        $analysis = $this->analysis();
        $item = $analysis->trainingItems()->first();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-aspect-source="aspek-'.$item->id.'"', $html);
        $this->assertStringContainsString('id="aspek-'.$item->id.'"', $html);
        $this->assertStringContainsString('blok kata pada teks', $html);
    }

    public function test_draf_otomatis_dikunci_per_analisis(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        // Kunci localStorage menyertakan id analisis, supaya draf satu
        // analisis tidak tertimpa draf analisis lain di peramban yang sama.
        $this->assertStringContainsString("koreksi-draf:{$analysis->id}", $html);
        $this->assertStringContainsString('pulihkanDraf', $html);
        $this->assertStringContainsString("addEventListener('submit'", $html);
    }

    public function test_tidak_muncul_untuk_analisis_sentimen_murni(): void
    {
        $analysis = $this->analysis('sentiment');

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        // Teksnya tetap boleh punya penanda data-aspect-source (JS akan
        // menemukan tidak ada kolom aspek dan diam saja), tetapi kolom serta
        // petunjuknya sendiri tidak boleh dirender untuk tipe ini.
        $this->assertStringNotContainsString('Koreksi Aspek', $html);
        $this->assertStringNotContainsString('blok kata pada teks', $html);
    }
}
