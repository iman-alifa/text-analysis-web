<?php

namespace App\Services;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;

class TrainingItemService
{
    /**
     * Extract predictions from analysis result JSON into training_items table.
     * Called lazily when training workspace or feedback page is first accessed.
     */
    public function extractJsonToTable(TextAnalysis $analysis): void
    {
        if (!$analysis->result) return;

        // 1. Build aspect keyword dictionary from global aspect_results
        $globalAspectsRaw = $analysis->result->aspect_results ?? [];
        if (is_string($globalAspectsRaw)) {
            $globalAspectsRaw = json_decode($globalAspectsRaw, true);
        }

        $aspectKeywords = [];
        if (is_array($globalAspectsRaw)) {
            foreach ($globalAspectsRaw as $item) {
                if (isset($item['aspect'])) {
                    $aspectKeywords[] = strtolower($item['aspect']);
                }
            }
        }

        // 2. Get per-row prediction data
        $sourceData = $analysis->result->predictions
                   ?? $analysis->result->result
                   ?? [];

        $rows = [];
        if (is_string($sourceData)) {
            $rows = json_decode($sourceData, true);
        } elseif (is_array($sourceData)) {
            $rows = $sourceData;
        }

        if (isset($rows['results']) && is_array($rows['results'])) {
            $rows = $rows['results'];
        }

        if (!is_array($rows) || empty($rows)) return;

        $batch = [];
        $now = now();

        foreach ($rows as $row) {
            if (empty($row['text'])) continue;

            // Normalise sentiment
            $sentimentLabel = 'neutral';
            if (isset($row['sentiment'])) {
                $sentimentLabel = is_array($row['sentiment'])
                    ? ($row['sentiment']['label'] ?? 'neutral')
                    : $row['sentiment'];
            }
            $confidence = $row['confidence'] ?? $row['score'] ?? 0;

            // Map aspects from global dictionary if not present on the row
            $rowAspects = $row['aspects'] ?? [];
            if (empty($rowAspects) && !empty($aspectKeywords)) {
                $textLower = strtolower($row['text']);
                foreach ($aspectKeywords as $keyword) {
                    if (preg_match("/\b" . preg_quote($keyword, '/') . "\b/i", $textLower)) {
                        $rowAspects[] = $keyword;
                    }
                }
            }

            $batch[] = [
                'text_analysis_id' => $analysis->id,
                'text_content'     => $row['text'],
                'predicted_sentiment' => strtolower($sentimentLabel),
                'confidence_score'    => (float) $confidence,
                'detected_aspects'    => json_encode(array_values(array_unique($rowAspects))),
                'is_corrected'        => false,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

            if (count($batch) >= 200) {
                TrainingItem::insert($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            TrainingItem::insert($batch);
        }
    }
}
