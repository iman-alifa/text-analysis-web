<?php

namespace Tests\Feature;

use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Models\User;
use App\Services\ModelEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Koherensi topik dihitung di dua tempat: blok `quality` dari NLP API dan
 * implementasi PHP di ModelEvaluationService. Menampilkan keduanya membuat satu
 * analisis punya dua angka berbeda, jadi angka dari API didahulukan.
 */
class TopicCoherenceSourceTest extends TestCase
{
    use RefreshDatabase;

    private function analysisWith(array $topicResults): TextAnalysis
    {
        $analysis = TextAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Analisis topik',
            'input_type' => 'manual',
            'analysis_type' => 'topic',
            'raw_data' => ['pajak naik rakyat menjerit', 'pelayanan publik buruk sekali'],
            'total_records' => 2,
            'status' => 'completed',
        ]);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'topic_results' => $topicResults,
        ]);

        return $analysis->fresh('result');
    }

    private function topics(): array
    {
        return [
            ['topic_id' => 0, 'words' => ['pajak', 'rakyat', 'naik'], 'proportion' => 0.5],
            ['topic_id' => 1, 'words' => ['pelayanan', 'publik', 'buruk'], 'proportion' => 0.5],
        ];
    }

    public function test_memakai_angka_dari_api_kalau_tersedia(): void
    {
        $analysis = $this->analysisWith([
            'topics' => $this->topics(),
            'word_frequencies' => [['word' => 'pajak', 'frequency' => 5]],
            'quality' => [
                'c_v' => 0.5032,
                'c_npmi' => -0.394,
                'diversity' => 0.9222,
                'outlier_rate' => 0.1638,
            ],
        ]);

        $hasil = (new ModelEvaluationService)->buildTopicEvaluation($analysis, new Collection);

        $this->assertSame('nlp-api', $hasil['source']);
        $this->assertSame(0.5032, $hasil['coherence_score']);
        $this->assertSame('Baik untuk teks pendek', $hasil['coherence_label']);
        $this->assertSame(0.9222, $hasil['diversity']);
        $this->assertSame(0.1638, $hasil['outlier_rate']);
        $this->assertSame(2, $hasil['topic_count']);
    }

    public function test_kembali_ke_perhitungan_php_untuk_analisis_lama(): void
    {
        // Analisis lama tidak menyimpan blok quality
        $analysis = $this->analysisWith([
            'topics' => $this->topics(),
            'word_frequencies' => [['word' => 'pajak', 'frequency' => 5]],
        ]);

        $items = new Collection;
        $hasil = (new ModelEvaluationService)->buildTopicEvaluation($analysis, $items);

        $this->assertSame('php-fallback', $hasil['source'] ?? ($hasil['available'] ? 'php-fallback' : 'n/a'));
    }

    public function test_label_sama_dengan_yang_dipakai_halaman_hasil(): void
    {
        foreach ([[0.25, 'Tidak koheren'], [0.35, 'Lemah'], [0.50, 'Baik untuk teks pendek'], [0.60, 'Sangat baik'], [0.80, 'Patut dicurigai']] as [$cv, $label]) {
            $analysis = $this->analysisWith([
                'topics' => $this->topics(),
                'quality' => ['c_v' => $cv],
            ]);

            $hasil = (new ModelEvaluationService)->buildTopicEvaluation($analysis, new Collection);

            $this->assertSame($label, $hasil['coherence_label'], "c_v {$cv}");
        }
    }
}
