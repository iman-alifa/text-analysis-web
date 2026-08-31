<?php

namespace App\Services;

use App\Models\EvaluationSnapshot;
use App\Models\ModelTraining;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use Illuminate\Support\Collection;

class ModelEvaluationService
{
    public function buildEvaluationSummary(TextAnalysis $analysis, Collection $correctedItems, ?Collection $allItems = null): array
    {
        return [
            'sentiment' => $this->buildSentimentEvaluation($analysis->analysis_type, $correctedItems),
            'aspect' => $this->buildAspectEvaluation($analysis->analysis_type, $correctedItems),
            'topic' => $this->buildTopicEvaluation($analysis, $allItems),
            'corrected_total' => $correctedItems->count(),
        ];
    }

    /**
     * Evaluasi global atas seluruh data terkoreksi (lintas analisis), dipakai
     * untuk membandingkan performa model antar-iterasi active learning.
     */
    public function buildGlobalEvaluation(?Collection $correctedItems = null): array
    {
        $items = $correctedItems ?? TrainingItem::where('is_corrected', true)->get();

        return [
            'sentiment' => $this->buildSentimentEvaluation('sentiment', $items),
            'aspect' => $this->buildAspectEvaluation('aspect', $items),
            'corrected_total' => $items->count(),
        ];
    }

    /**
     * Simpan potret metrik saat ini supaya perubahannya bisa ditelusuri
     * setelah model dilatih ulang.
     */
    public function captureSnapshot(?ModelTraining $training = null, ?string $note = null): EvaluationSnapshot
    {
        $evaluation = $this->buildGlobalEvaluation();
        $sentiment = $evaluation['sentiment'] ?? [];
        $aspect = $evaluation['aspect'] ?? [];

        return EvaluationSnapshot::create([
            'model_training_id' => $training?->id,
            'note' => $note,
            'corrected_total' => $evaluation['corrected_total'],
            'sentiment_accuracy' => ($sentiment['available'] ?? false) ? $sentiment['accuracy'] : null,
            'sentiment_weighted_f1' => ($sentiment['available'] ?? false) ? $sentiment['weighted_f1'] : null,
            'sentiment_rows' => ($sentiment['available'] ?? false) ? $sentiment['evaluated_rows'] : 0,
            'aspect_f1' => ($aspect['available'] ?? false) ? $aspect['f1'] : null,
            'aspect_exact_match' => ($aspect['available'] ?? false) ? $aspect['exact_match'] : null,
            'aspect_rows' => ($aspect['available'] ?? false) ? $aspect['evaluated_rows'] : 0,
            'metrics' => $evaluation,
        ]);
    }

    public function buildSentimentEvaluation(string $analysisType, Collection $correctedItems): ?array
    {
        if (!in_array($analysisType, ['sentiment', 'combined'])) {
            return null;
        }

        $labels = ['positive', 'neutral', 'negative'];
        $confusion = [];
        foreach ($labels as $actual) {
            foreach ($labels as $predicted) {
                $confusion[$actual][$predicted] = 0;
            }
        }

        $compared = 0;
        $correct = 0;

        foreach ($correctedItems as $item) {
            $predicted = strtolower((string) ($item->predicted_sentiment ?? ''));
            $actual = strtolower((string) ($item->corrected_sentiment ?? ''));

            if (!in_array($predicted, $labels) || !in_array($actual, $labels)) {
                continue;
            }

            $confusion[$actual][$predicted]++;
            $compared++;

            if ($actual === $predicted) {
                $correct++;
            }
        }

        if ($compared === 0) {
            return [
                'available' => false,
                'message' => 'Belum ada data koreksi sentimen untuk evaluasi.',
            ];
        }

        $perClass = [];
        $weightedPrecision = 0.0;
        $weightedRecall = 0.0;
        $weightedF1 = 0.0;

        foreach ($labels as $label) {
            $tp = $confusion[$label][$label];
            $fp = 0;
            $fn = 0;

            foreach ($labels as $other) {
                if ($other !== $label) {
                    $fp += $confusion[$other][$label];
                    $fn += $confusion[$label][$other];
                }
            }

            $support = array_sum($confusion[$label]);
            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
            $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0;

            $perClass[$label] = [
                'precision' => round($precision * 100, 1),
                'recall' => round($recall * 100, 1),
                'f1' => round($f1 * 100, 1),
                'support' => $support,
            ];

            $weight = $compared > 0 ? $support / $compared : 0;
            $weightedPrecision += $precision * $weight;
            $weightedRecall += $recall * $weight;
            $weightedF1 += $f1 * $weight;
        }

        return [
            'available' => true,
            'accuracy' => round(($correct / $compared) * 100, 1),
            'weighted_precision' => round($weightedPrecision * 100, 1),
            'weighted_recall' => round($weightedRecall * 100, 1),
            'weighted_f1' => round($weightedF1 * 100, 1),
            'evaluated_rows' => $compared,
            'per_class' => $perClass,
            'confusion_matrix' => $confusion,
            'labels' => $labels,
        ];
    }

    public function buildAspectEvaluation(string $analysisType, Collection $correctedItems): ?array
    {
        if (!in_array($analysisType, ['aspect', 'combined'])) {
            return null;
        }

        $tp = 0;
        $fp = 0;
        $fn = 0;
        $exactMatches = 0;
        $compared = 0;

        foreach ($correctedItems as $item) {
            if (is_null($item->corrected_aspects)) {
                continue;
            }

            $predicted = $this->normalizeAspects($item->detected_aspects ?? []);
            $actual = $this->normalizeAspects($item->corrected_aspects ?? []);

            $predSet = array_fill_keys($predicted, true);
            $actualSet = array_fill_keys($actual, true);

            $tp += count(array_intersect_key($predSet, $actualSet));
            $fp += count(array_diff_key($predSet, $actualSet));
            $fn += count(array_diff_key($actualSet, $predSet));

            if ($predicted === $actual) {
                $exactMatches++;
            }

            $compared++;
        }

        if ($compared === 0) {
            return [
                'available' => false,
                'message' => 'Belum ada data koreksi aspek untuk evaluasi.',
            ];
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
        $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0;

        return [
            'available' => true,
            'exact_match' => round(($exactMatches / $compared) * 100, 1),
            'precision' => round($precision * 100, 1),
            'recall' => round($recall * 100, 1),
            'f1' => round($f1 * 100, 1),
            'evaluated_rows' => $compared,
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
        ];
    }

    // public function buildTopicEvaluation(TextAnalysis $analysis, ?Collection $allItems = null): ?array
    // {
    //     if (!in_array($analysis->analysis_type, ['topic', 'combined'])) {
    //         return null;
    //     }

    //     $topicResults = $analysis->result->topic_results ?? [];
    //     if (is_string($topicResults)) {
    //         $topicResults = json_decode($topicResults, true) ?? [];
    //     }

    //     $topics = $topicResults['topics'] ?? [];
    //     $wordFrequencies = $topicResults['word_frequencies'] ?? [];

    //     if (empty($topics)) {
    //         return [
    //             'available' => false,
    //             'message' => 'Data topik belum tersedia untuk evaluasi.',
    //         ];
    //     }

    //     $corpusTexts = $this->resolveCorpusTexts($analysis, $allItems);
    //     $coherence = $this->calculateTopicCoherence($topics, $corpusTexts);

    //     // Batas ini dipakai sebagai rule-of-thumb internal untuk UMass coherence:
    //     // skor yang lebih tinggi (kurang negatif, makin mendekati 0) menandakan topik lebih koheren.
    //     $coherenceLabel = 'Rendah';
    //     if ($coherence['score'] >= -1.0) {
    //         $coherenceLabel = 'Baik';
    //     } elseif ($coherence['score'] >= -2.0) {
    //         $coherenceLabel = 'Sedang';
    //     }

    //     $uniqueTopicWords = collect($topics)
    //         ->pluck('words')
    //         ->flatten()
    //         ->filter()
    //         ->unique()
    //         ->count();

    //     return [
    //         'available' => true,
    //         'topic_count' => count($topics),
    //         'coherence_score' => round($coherence['score'], 4),
    //         'coherence_pairs' => $coherence['pairs'],
    //         'coherence_label' => $coherenceLabel,
    //         'unique_topic_words' => $uniqueTopicWords,
    //         'word_frequency_terms' => is_array($wordFrequencies) ? count($wordFrequencies) : 0,
    //     ];
    // }

    public function buildTopicEvaluation(TextAnalysis $analysis, ?Collection $allItems = null): ?array
    {
        if (!in_array($analysis->analysis_type, ['topic', 'combined'])) {
            return null;
        }

        $topicResults = $analysis->result->topic_results ?? [];
        if (is_string($topicResults)) {
            $topicResults = json_decode($topicResults, true) ?? [];
        }

        $topics         = $topicResults['topics'] ?? [];
        $wordFrequencies = $topicResults['word_frequencies'] ?? [];

        if (empty($topics)) {
            return [
                'available' => false,
                'message'   => 'Data topik belum tersedia untuk evaluasi.',
            ];
        }

        $corpusTexts = $this->resolveCorpusTexts($analysis, $allItems);
        $coherence   = $this->calculateTopicCoherence($topics, $corpusTexts);

        if (empty($coherence['pairs'])) {
            return [
                'available' => false,
                'message'   => 'Tidak cukup pasangan kata untuk menghitung koherensi topik.',
            ];
        }

        // CV Coherence (Röder et al., 2015)
        // Rentang: 0 hingga 1 — semakin tinggi semakin koheren
        $score = $coherence['score'];
        $coherenceLabel = match (true) {
            $score >= 0.7 => 'Sangat Baik',  // topik sangat koheren dan terdefinisi jelas
            $score >= 0.5 => 'Baik',          // topik koheren, hasil dapat diandalkan
            $score >= 0.3 => 'Sedang',        // topik cukup koheren, perlu review
            default       => 'Rendah',        // topik tidak koheren, perlu tuning jumlah topik
        };

        $uniqueTopicWords = collect($topics)
            ->pluck('words')
            ->flatten()
            ->filter()
            ->unique()
            ->count();

        $avgWordsPerTopic = collect($topics)
            ->map(fn($t) => count($t['words'] ?? []))
            ->average();

        return [
            'available'            => true,
            'topic_count'          => count($topics),
            'coherence_score'      => round($score, 4),
            'coherence_pairs'      => $coherence['pairs'],
            'coherence_label'      => $coherenceLabel,
            'unique_topic_words'   => $uniqueTopicWords,
            'avg_words_per_topic'  => round($avgWordsPerTopic, 1),
            'word_frequency_terms' => is_array($wordFrequencies) ? count($wordFrequencies) : 0,
        ];
    }

    private function resolveCorpusTexts(TextAnalysis $analysis, ?Collection $allItems = null): array
    {
        if ($allItems && $allItems->isNotEmpty()) {
            return $allItems
                ->pluck('text_content')
                ->filter()
                ->map(fn ($text) => (string) $text)
                ->values()
                ->all();
        }

        if (is_array($analysis->raw_data)) {
            return collect($analysis->raw_data)
                ->filter()
                ->map(fn ($text) => (string) $text)
                ->values()
                ->all();
        }

        return [];
    }

    // private function calculateTopicCoherence(array $topics, array $corpusTexts): array
    // {
    //     if (empty($corpusTexts)) {
    //         return ['score' => 0.0, 'pairs' => 0];
    //     }

    //     $documents = collect($corpusTexts)
    //         ->map(function ($text) {
    //             $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY);
    //             return array_fill_keys(array_unique($tokens ?: []), true);
    //         })
    //         ->filter(fn ($docTokens) => !empty($docTokens))
    //         ->values();

    //     if ($documents->isEmpty()) {
    //         return ['score' => 0.0, 'pairs' => 0];
    //     }

    //     $topicScores = [];
    //     $totalPairs = 0;

    //     foreach ($topics as $topic) {
    //         $words = collect($topic['words'] ?? [])
    //             ->map(fn ($word) => mb_strtolower(trim((string) $word)))
    //             ->filter()
    //             ->unique()
    //             ->take(10)
    //             ->values()
    //             ->all();

    //         if (count($words) < 2) {
    //             continue;
    //         }

    //         $topicPairScores = [];
    //         $wordCount = count($words);

    //         for ($j = 1; $j < $wordCount; $j++) {
    //             for ($i = 0; $i < $j; $i++) {
    //                 $w1 = $words[$i];
    //                 $w2 = $words[$j];
    //                 $dW2 = 0;
    //                 $dW1W2 = 0;

    //                 foreach ($documents as $docTokens) {
    //                     $hasW2 = isset($docTokens[$w2]);
    //                     if ($hasW2) {
    //                         $dW2++;
    //                     }

    //                     if ($hasW2 && isset($docTokens[$w1])) {
    //                         $dW1W2++;
    //                     }
    //                 }

    //                 if ($dW2 === 0) {
    //                     continue;
    //                 }

    //                 // UMass topic coherence (Mimno et al., 2011):
    //                 // D(w_i, w_j) = jumlah dokumen yang memuat kedua kata,
    //                 // D(w_j) = jumlah dokumen yang memuat kata pembanding.
    //                 // Rumus: log((D(w_i, w_j) + 1) / D(w_j)); nilai lebih tinggi = topik lebih koheren.
    //                 $topicPairScores[] = log(($dW1W2 + 1) / $dW2);
    //             }
    //         }

    //         if (!empty($topicPairScores)) {
    //             $topicScores[] = array_sum($topicPairScores) / count($topicPairScores);
    //             $totalPairs += count($topicPairScores);
    //         }
    //     }

    //     if (empty($topicScores)) {
    //         return ['score' => 0.0, 'pairs' => 0];
    //     }

    //     return [
    //         'score' => array_sum($topicScores) / count($topicScores),
    //         'pairs' => $totalPairs,
    //     ];
    // }

    /**
     * Hitung CV Coherence (Röder et al., 2015)
     * Pipeline: sliding window → NPMI → cosine similarity → rata-rata
     * Rentang: 0 hingga 1 (semakin tinggi = semakin koheren)
     */
    private function calculateTopicCoherence(array $topics, array $corpusTexts, int $windowSize = 10): array
    {
        if (empty($corpusTexts) || empty($topics)) {
            return ['score' => 0.0, 'pairs' => 0];
        }

        // Langkah 1: Tokenisasi semua dokumen
        $tokenizedDocs = array_map(function (string $text): array {
            return preg_split('/\s+/', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY);
        }, $corpusTexts);

        // Langkah 2: Hitung co-occurrence dalam sliding window
        $coOccurrence = [];  // ['word_a|word_b' => count]
        $wordCount    = [];  // ['word' => count]
        $totalWindows = 0;

        foreach ($tokenizedDocs as $tokens) {
            $len = count($tokens);
            for ($i = 0; $i < $len; $i++) {
                $window = array_slice($tokens, $i, $windowSize);
                $unique = array_unique($window);
                $totalWindows++;

                foreach ($unique as $word) {
                    $wordCount[$word] = ($wordCount[$word] ?? 0) + 1;
                }

                $uniqueList = array_values($unique);
                $wLen = count($uniqueList);
                for ($a = 0; $a < $wLen; $a++) {
                    for ($b = $a + 1; $b < $wLen; $b++) {
                        $pair = $this->makePairKey($uniqueList[$a], $uniqueList[$b]);
                        $coOccurrence[$pair] = ($coOccurrence[$pair] ?? 0) + 1;
                    }
                }
            }
        }

        if ($totalWindows === 0) {
            return ['score' => 0.0, 'pairs' => 0];
        }

        // Langkah 3: Hitung NPMI tiap pasangan kata dalam setiap topik,
        // lalu representasikan tiap kata sebagai vektor NPMI terhadap top-N kata lain
        $topicScores = [];

        foreach ($topics as $topic) {
            $words = array_values(array_filter($topic['words'] ?? []));

            if (count($words) < 2) {
                continue;
            }

            // Bangun word vector berbasis NPMI untuk setiap kata dalam topik
            $vectors = [];
            foreach ($words as $word) {
                $vec = [];
                foreach ($words as $context) {
                    if ($word === $context) {
                        continue;
                    }
                    $vec[$context] = $this->calculateNpmi(
                        $word,
                        $context,
                        $coOccurrence,
                        $wordCount,
                        $totalWindows
                    );
                }
                $vectors[$word] = $vec;
            }

            // Langkah 4: Hitung cosine similarity antar semua pasangan word vector
            $similarities = [];
            $wordList     = array_keys($vectors);
            $wCount       = count($wordList);

            for ($i = 0; $i < $wCount; $i++) {
                for ($j = $i + 1; $j < $wCount; $j++) {
                    $similarities[] = $this->cosineSimilarity(
                        $vectors[$wordList[$i]],
                        $vectors[$wordList[$j]],
                        $words
                    );
                }
            }

            if (!empty($similarities)) {
                $topicScores[] = array_sum($similarities) / count($similarities);
            }
        }

        if (empty($topicScores)) {
            return ['score' => 0.0, 'pairs' => 0];
        }

        // Langkah 5: Rata-rata CV score semua topik
        $avgScore  = array_sum($topicScores) / count($topicScores);
        $totalPairs = array_sum(array_map(function ($topic) {
            $n = count(array_filter($topic['words'] ?? []));
            return $n >= 2 ? ($n * ($n - 1)) / 2 : 0;
        }, $topics));

        return [
            'score' => max(0.0, min(1.0, $avgScore)), // clamp 0–1
            'pairs' => (int) $totalPairs,
        ];
    }

    /**
     * Hitung Normalized Pointwise Mutual Information (NPMI)
     * NPMI(a,b) = log(P(a,b) / (P(a) * P(b))) / -log(P(a,b))
     * Rentang: -1 hingga +1
     */
    private function calculateNpmi(
        string $wordA,
        string $wordB,
        array $coOccurrence,
        array $wordCount,
        int $totalWindows
    ): float {
        $pA  = ($wordCount[$wordA] ?? 0) / $totalWindows;
        $pB  = ($wordCount[$wordB] ?? 0) / $totalWindows;
        $key = $this->makePairKey($wordA, $wordB);
        $pAB = ($coOccurrence[$key] ?? 0) / $totalWindows;

        if ($pAB <= 0 || $pA <= 0 || $pB <= 0) {
            return -1.0; // tidak pernah muncul bersama = NPMI minimum
        }

        $pmi  = log($pAB / ($pA * $pB));
        $npmi = $pmi / (-log($pAB));

        return max(-1.0, min(1.0, $npmi));
    }

    /**
     * Hitung cosine similarity antara dua word vector (array NPMI)
     */
    private function cosineSimilarity(array $vecA, array $vecB, array $dimensions): float
    {
        $dot  = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($dimensions as $dim) {
            $a    = $vecA[$dim] ?? 0.0;
            $b    = $vecB[$dim] ?? 0.0;
            $dot  += $a * $b;
            $magA += $a * $a;
            $magB += $b * $b;
        }

        $denom = sqrt($magA) * sqrt($magB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }

    /**
     * Buat key konsisten untuk pasangan kata (urutan tidak berpengaruh)
     */
    private function makePairKey(string $wordA, string $wordB): string
    {
        return $wordA < $wordB ? "{$wordA}|{$wordB}" : "{$wordB}|{$wordA}";
    }

    private function normalizeAspects(array|string|null $aspects): array
    {
        if (is_string($aspects)) {
            $decoded = json_decode($aspects, true);
            $aspects = is_array($decoded) ? $decoded : explode(',', $aspects);
        }

        if (!is_array($aspects)) {
            return [];
        }

        $normalized = array_map(
            fn ($aspect) => strtolower(trim((string) $aspect)),
            $aspects
        );

        $normalized = array_values(array_filter($normalized, fn ($aspect) => $aspect !== ''));
        sort($normalized);

        return array_values(array_unique($normalized));
    }
}
