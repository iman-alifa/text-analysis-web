<?php

namespace Tests\Feature;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baris berisi emotikon/teks kosong ditandai NLP API sebagai `method: 'empty'`
 * dan tidak pernah benar-benar dinilai model (confidence 0, sentimen sekadar
 * pengisi). Tidak ada apa pun untuk dikoreksi pada baris itu, jadi ia
 * disingkirkan dari halaman koreksi - bukan cuma disembunyikan tampilannya,
 * tetapi juga dari hitungan "Semua baris (N)" / "Baris yang ragu (N)".
 */
class EmptyPredictionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(): TextAnalysis
    {
        $user = User::factory()->create();

        $analysis = TextAnalysis::create([
            'user_id' => $user->id,
            'title' => 'Analisis dengan baris kosong',
            'input_type' => 'manual',
            'analysis_type' => 'sentiment',
            'raw_data' => ['bagus sekali', ':)', 'lumayan', '😀😀'],
            'total_records' => 4,
            'status' => 'completed',
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'bagus sekali',
            'predicted_sentiment' => 'positive',
            'confidence_score' => 0.95,
            'predicted_method' => 'indobert',
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => ':)',
            'predicted_sentiment' => 'neutral',
            'confidence_score' => 0.0,
            'predicted_method' => 'empty',
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'lumayan',
            'predicted_sentiment' => 'neutral',
            'confidence_score' => 0.60,
            'predicted_method' => 'indobert',
        ]);

        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => '😀😀',
            'predicted_sentiment' => 'neutral',
            'confidence_score' => 0.0,
            'predicted_method' => 'empty',
        ]);

        return $analysis;
    }

    public function test_baris_kosong_tidak_tampil_di_daftar_koreksi(): void
    {
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bagus sekali', $html);
        $this->assertStringContainsString('lumayan', $html);
        $this->assertStringNotContainsString(':)', $html);
        $this->assertStringNotContainsString('😀😀', $html);
    }

    public function test_hitungan_semua_baris_tidak_ikut_menghitung_baris_kosong(): void
    {
        $analysis = $this->analysis();

        // 4 baris tersimpan, tetapi hanya 2 yang benar-benar dinilai model.
        $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->assertSee('Semua baris (2)', false);
    }

    public function test_baris_kosong_tidak_mendominasi_fokus_ragu(): void
    {
        // Baris kosong tersimpan dengan confidence 0 - kalau tidak disaring,
        // ia akan selalu berada paling atas pada pengurutan "paling ragu
        // dulu", padahal justru baris yang paling tidak berguna dikoreksi.
        $analysis = $this->analysis();

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id).'?fokus=ragu')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(':)', $html);
        $this->assertStringNotContainsString('😀😀', $html);
        // "lumayan" (0.60) tetap lebih ragu daripada "bagus sekali" (0.95).
        $this->assertStringContainsString('lumayan', $html);
    }

    public function test_placeholder_koreksi_aspek_bukan_contoh_data(): void
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis aspek',
            'input_type' => 'manual',
            'analysis_type' => 'aspect',
            'raw_data' => ['a'],
            'total_records' => 1,
            'status' => 'completed',
        ]);

        // Tanpa aspek terdeteksi sama sekali - kasus placeholder benar-benar tampil.
        TrainingItem::create([
            'text_analysis_id' => $analysis->id,
            'text_content' => 'a',
            'detected_aspects' => [],
            'confidence_score' => 0.8,
            'predicted_method' => 'indobert',
        ]);

        $html = $this->actingAs($analysis->user)
            ->get(route('analysis.feedback', $analysis->id))
            ->assertOk()
            ->getContent();

        // Placeholder lama berupa contoh nama aspek nyata - gampang dikira
        // aspek yang memang sudah terdeteksi model.
        $this->assertStringNotContainsString('harga, kualitas, pelayanan', $html);
        $this->assertStringContainsString('Belum ada aspek terdeteksi', $html);
    }
}
