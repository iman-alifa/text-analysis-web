<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman koreksi merender seluruh baris dalam satu form - terukur 2,1 MB untuk
 * 289 baris. Daftarnya kini dipaginasi, dengan baris paling tidak yakin lebih dulu.
 */
class FeedbackPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(int $jumlah = 60): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Analisis koreksi',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['a'],
            'total_records' => $jumlah,
            'status' => 'completed',
        ]);

        for ($i = 0; $i < $jumlah; $i++) {
            TrainingItem::create([
                'text_analysis_id' => $analysis->id,
                'text_content' => "kalimat nomor {$i}",
                'predicted_sentiment' => 'neutral',
                // Semakin besar i, semakin yakin modelnya
                'confidence_score' => 0.10 + ($i / 100),
                'is_corrected' => false,
            ]);
        }

        return $analysis;
    }

    public function test_hanya_satu_halaman_baris_yang_dirender(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        // Satu input item_id per baris koreksi
        $this->assertSame(25, substr_count($html, '][item_id]'));
        $this->assertStringContainsString('Halaman 1 dari 3', $html);
        $this->assertStringContainsString('dari 60 baris', $html);
    }

    public function test_baris_paling_tidak_yakin_didahulukan(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        // Keyakinan terendah ada pada kalimat 0, dan yang tertinggi tidak
        // ikut pada halaman pertama.
        $this->assertStringContainsString('kalimat nomor 0', $html);
        $this->assertStringNotContainsString('kalimat nomor 59', $html);
    }

    public function test_halaman_berikutnya_menampilkan_baris_lain(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id).'?page=3')
            ->assertOk()
            ->assertSee('Halaman 3 dari 3')
            ->getContent();

        $this->assertStringContainsString('kalimat nomor 59', $html);
        $this->assertStringNotContainsString('>kalimat nomor 0<', $html);
    }

    public function test_evaluasi_tetap_dihitung_dari_seluruh_baris(): void
    {
        $analysis = $this->analysis();

        // Koreksi satu baris yang berada di halaman terakhir
        TrainingItem::where('text_analysis_id', $analysis->id)
            ->orderByDesc('confidence_score')
            ->first()
            ->update([
                'corrected_sentiment' => 'positive',
                'is_corrected' => true,
                'verified_by' => $analysis->user_id,
                'verified_at' => now(),
            ]);

        // Meskipun barisnya tidak tampil di halaman 1, evaluasinya ikut terhitung
        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->assertSee('Evaluasi', false);
    }

    public function test_setelah_menyimpan_kembali_ke_halaman_yang_sama(): void
    {
        $analysis = $this->analysis();
        $item = TrainingItem::where('text_analysis_id', $analysis->id)->first();

        $this->actingAs($analysis->user)
            ->post(route('analysis.feedback.store', $analysis->id), [
                'page' => 2,
                'corrections' => [
                    ['item_id' => $item->id, 'corrected_sentiment' => 'positive'],
                ],
            ])
            ->assertRedirect(route('analysis.feedback', ['id' => $analysis->id, 'page' => 2]));
    }

    public function test_analisis_milik_pengguna_lain_tetap_tertutup(): void
    {
        $analysis = $this->analysis();

        $this->actingAs(User::factory()->create())
            ->get(route('analysis.feedback', $analysis->id))
            ->assertForbidden();
    }
}
