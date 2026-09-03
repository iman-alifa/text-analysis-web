<?php

namespace Tests\Feature;

use App\Jobs\ProcessTextAnalysis;
use App\Models\AnalysisResult;
use App\Models\CustomStopword;
use App\Models\PreprocessingConfig;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\User;
use App\Services\TrainingItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AnalysisPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(array $attributes = []): TextAnalysis
    {
        $user = User::factory()->create();

        return TextAnalysis::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Analisis uji',
            'input_type' => 'manual',
            'analysis_type' => 'topic',
            'raw_data' => ['teks satu', 'teks dua'],
            'total_records' => 2,
            'status' => 'pending',
        ], $attributes));
    }

    public function test_metadata_tersimpan_sebagai_array_yang_bisa_dibaca(): void
    {
        $analysis = $this->makeAnalysis([
            'metadata' => ['num_topics' => 8, 'predefined_aspects' => ['harga']],
        ]);

        $analysis->refresh();

        $this->assertSame(8, $analysis->metadata['num_topics']);
        $this->assertSame(['harga'], $analysis->metadata['predefined_aspects']);
    }

    public function test_metadata_lama_yang_double_encoded_tetap_terbaca(): void
    {
        $analysis = $this->makeAnalysis();

        // Bentuk yang tersimpan sebelum perbaikan: json_encode manual + cast array
        TextAnalysis::where('id', $analysis->id)->update([
            'metadata' => json_encode(json_encode(['num_topics' => 12])),
        ]);

        $this->assertSame(12, $analysis->fresh()->metadata['num_topics']);
    }

    public function test_preprocessing_config_mengikuti_pilihan_user_dan_stopword_admin(): void
    {
        $admin = User::factory()->create();

        PreprocessingConfig::create([
            'name' => 'Default',
            'case_folding' => true,
            'remove_punctuation' => true,
            'remove_numbers' => false,
            'remove_stopwords' => true,
            'stemming' => true,
            'lemmatization' => false,
            'is_default' => true,
        ]);

        $chosen = PreprocessingConfig::create([
            'name' => 'Tanpa stemming',
            'case_folding' => true,
            'remove_punctuation' => false,
            'remove_numbers' => true,
            'remove_stopwords' => false,
            'stemming' => false,
            'lemmatization' => false,
            'custom_stopwords' => ['nih'],
            'is_default' => false,
        ]);

        CustomStopword::create(['word' => 'Banget', 'added_by' => $admin->id]);

        $analysis = $this->makeAnalysis([
            'metadata' => ['preprocessing_config_id' => $chosen->id],
        ]);

        $method = new ReflectionMethod(ProcessTextAnalysis::class, 'getPreprocessingConfig');
        $method->setAccessible(true);
        $config = $method->invoke(new ProcessTextAnalysis($analysis->fresh()));

        $this->assertFalse($config['stemming']);
        $this->assertTrue($config['remove_numbers']);
        $this->assertContains('nih', $config['custom_stopwords']);
        $this->assertContains('banget', $config['custom_stopwords']);
    }

    public function test_preprocessing_config_jatuh_ke_default_kalau_user_tidak_memilih(): void
    {
        PreprocessingConfig::create([
            'name' => 'Default',
            'case_folding' => true,
            'remove_punctuation' => true,
            'remove_numbers' => true,
            'remove_stopwords' => true,
            'stemming' => true,
            'lemmatization' => false,
            'is_default' => true,
        ]);

        $analysis = $this->makeAnalysis();

        $method = new ReflectionMethod(ProcessTextAnalysis::class, 'getPreprocessingConfig');
        $method->setAccessible(true);
        $config = $method->invoke(new ProcessTextAnalysis($analysis));

        $this->assertTrue($config['remove_numbers']);
    }

    public function test_mode_rule_based_tanpa_daftar_aspek_kembali_ke_automatic(): void
    {
        $analysis = $this->makeAnalysis([
            'analysis_type' => 'aspect',
            'metadata' => ['aspect_mode' => 'rule-based'],
        ]);

        $method = new ReflectionMethod(ProcessTextAnalysis::class, 'getAspectMode');
        $method->setAccessible(true);
        $job = new ProcessTextAnalysis($analysis->fresh());

        $this->assertSame('automatic', $method->invoke($job, null));
        $this->assertSame('rule-based', $method->invoke($job, ['harga']));
    }

    public function test_hasil_analisis_aspek_menyimpan_baris_prediksi_per_teks(): void
    {
        $analysis = $this->makeAnalysis(['analysis_type' => 'aspect']);

        $method = new ReflectionMethod(ProcessTextAnalysis::class, 'saveResults');
        $method->setAccessible(true);
        $method->invoke(new ProcessTextAnalysis($analysis), [
            'status' => 'success',
            'results' => [
                'aspect_sentiments' => [
                    ['aspect' => 'harga', 'count' => 1, 'sentiments' => ['positive' => 100, 'neutral' => 0, 'negative' => 0]],
                ],
                'document_aspects' => [['harga'], ['pelayanan']],
                'summary' => 'ringkasan aspek',
            ],
        ]);

        $result = AnalysisResult::where('text_analysis_id', $analysis->id)->firstOrFail();

        $this->assertCount(2, $result->predictions);
        $this->assertSame('teks satu', $result->predictions[0]['text']);
        $this->assertSame(['harga'], $result->predictions[0]['aspects']);
        $this->assertSame([['harga'], ['pelayanan']], $result->document_aspects);

        // Baris prediksi inilah yang membuat halaman feedback terisi
        (new TrainingItemService)->extractJsonToTable($analysis->fresh());
        $this->assertSame(2, TrainingItem::where('text_analysis_id', $analysis->id)->count());
    }

    public function test_analisis_combined_menyimpan_asosiasi_dan_aspek_per_baris(): void
    {
        $analysis = $this->makeAnalysis(['analysis_type' => 'combined']);

        $method = new ReflectionMethod(ProcessTextAnalysis::class, 'saveResults');
        $method->setAccessible(true);
        $method->invoke(new ProcessTextAnalysis($analysis), [
            'status' => 'success',
            'results' => [
                'sentiment' => [
                    'predictions' => [
                        ['text' => 'teks satu bersih', 'sentiment' => 'positive', 'confidence' => 0.9],
                        ['text' => 'teks dua bersih', 'sentiment' => 'negative', 'confidence' => 0.7],
                    ],
                    'distribution' => ['positive' => 50, 'neutral' => 0, 'negative' => 50],
                    'metrics' => ['total_texts' => 2],
                ],
                'aspect' => [
                    'aspect_sentiments' => [],
                    'document_aspects' => [['harga'], ['pelayanan']],
                ],
                'topic' => ['topics' => [], 'num_topics' => 0],
                'association' => [
                    'pmi_top_associations' => [
                        ['aspect' => 'harga', 'topic_id' => 0, 'pmi' => 1.2, 'co_occurrences' => 5],
                    ],
                    'heatmap_matrix' => [],
                ],
            ],
        ]);

        $result = AnalysisResult::where('text_analysis_id', $analysis->id)->firstOrFail();

        $this->assertSame('harga', $result->association_results['pmi_top_associations'][0]['aspect']);
        $this->assertSame(['harga'], $result->predictions[0]['aspects']);
        // teks asli dikembalikan, hasil preprocessing tetap disimpan terpisah
        $this->assertSame('teks satu', $result->predictions[0]['text']);
        $this->assertSame('teks satu bersih', $result->predictions[0]['processed_text']);
    }

    public function test_ekstraksi_training_item_tidak_menggandakan_baris(): void
    {
        $analysis = $this->makeAnalysis(['analysis_type' => 'sentiment']);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                ['text' => 'teks satu', 'sentiment' => 'positive', 'confidence' => 0.9, 'aspects' => ['harga']],
                ['text' => 'teks dua', 'sentiment' => 'negative', 'confidence' => 0.8],
            ],
        ]);

        $service = new TrainingItemService;
        $service->extractJsonToTable($analysis->fresh());
        $service->extractJsonToTable($analysis->fresh());

        $this->assertSame(2, TrainingItem::where('text_analysis_id', $analysis->id)->count());
        $this->assertSame(
            ['harga'],
            TrainingItem::where('text_content', 'teks satu')->first()->detected_aspects
        );
    }

    public function test_training_item_tanpa_sentimen_disimpan_null_bukan_neutral(): void
    {
        $analysis = $this->makeAnalysis(['analysis_type' => 'aspect']);

        AnalysisResult::create([
            'text_analysis_id' => $analysis->id,
            'predictions' => [
                ['text' => 'teks satu', 'aspects' => ['pelayanan']],
            ],
        ]);

        (new TrainingItemService)->extractJsonToTable($analysis->fresh());

        $this->assertNull(TrainingItem::first()->predicted_sentiment);
    }
}
