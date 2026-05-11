<?php

namespace App\Services;

use App\Models\TextAnalysis;
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

    public function buildTopicEvaluation(TextAnalysis $analysis, ?Collection $allItems = null): ?array
    {
        if (!in_array($analysis->analysis_type, ['topic', 'combined'])) {
            return null;
        }

        $topicResults = $analysis->result->topic_results ?? [];
        if (is_string($topicResults)) {
            $topicResults = json_decode($topicResults, true) ?? [];
        }

        $topics = $topicResults['topics'] ?? [];
        $wordFrequencies = $topicResults['word_frequencies'] ?? [];

        if (empty($topics)) {
            return [
                'available' => false,
                'message' => 'Data topik belum tersedia untuk evaluasi.',
            ];
        }

        $corpusTexts = $this->resolveCorpusTexts($analysis, $allItems);
        $coherence = $this->calculateTopicCoherence($topics, $corpusTexts);

        $coherenceLabel = 'Rendah';
        if ($coherence['score'] >= -1.0) {
            $coherenceLabel = 'Baik';
        } elseif ($coherence['score'] >= -2.0) {
            $coherenceLabel = 'Sedang';
        }

        $uniqueTopicWords = collect($topics)
            ->pluck('words')
            ->flatten()
            ->filter()
            ->unique()
            ->count();

        return [
            'available' => true,
            'topic_count' => count($topics),
            'coherence_score' => round($coherence['score'], 4),
            'coherence_pairs' => $coherence['pairs'],
            'coherence_label' => $coherenceLabel,
            'unique_topic_words' => $uniqueTopicWords,
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

    private function calculateTopicCoherence(array $topics, array $corpusTexts): array
    {
        if (empty($corpusTexts)) {
            return ['score' => 0.0, 'pairs' => 0];
        }

        $documents = collect($corpusTexts)
            ->map(function ($text) {
                $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY);
                return array_fill_keys(array_unique($tokens ?: []), true);
            })
            ->filter(fn ($docTokens) => !empty($docTokens))
            ->values();

        if ($documents->isEmpty()) {
            return ['score' => 0.0, 'pairs' => 0];
        }

        $topicScores = [];
        $totalPairs = 0;

        foreach ($topics as $topic) {
            $words = collect($topic['words'] ?? [])
                ->map(fn ($word) => mb_strtolower(trim((string) $word)))
                ->filter()
                ->unique()
                ->take(10)
                ->values()
                ->all();

            if (count($words) < 2) {
                continue;
            }

            $topicPairScores = [];
            $wordCount = count($words);

            for ($j = 1; $j < $wordCount; $j++) {
                for ($i = 0; $i < $j; $i++) {
                    $w1 = $words[$i];
                    $w2 = $words[$j];
                    $dW2 = 0;
                    $dW1W2 = 0;

                    foreach ($documents as $docTokens) {
                        $hasW2 = isset($docTokens[$w2]);
                        if ($hasW2) {
                            $dW2++;
                        }

                        if ($hasW2 && isset($docTokens[$w1])) {
                            $dW1W2++;
                        }
                    }

                    if ($dW2 === 0) {
                        continue;
                    }

                    $topicPairScores[] = log(($dW1W2 + 1) / $dW2);
                }
            }

            if (!empty($topicPairScores)) {
                $topicScores[] = array_sum($topicPairScores) / count($topicPairScores);
                $totalPairs += count($topicPairScores);
            }
        }

        if (empty($topicScores)) {
            return ['score' => 0.0, 'pairs' => 0];
        }

        return [
            'score' => array_sum($topicScores) / count($topicScores),
            'pairs' => $totalPairs,
        ];
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
