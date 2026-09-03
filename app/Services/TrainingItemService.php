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
        if (! $analysis->result) {
            return;
        }

        // Idempotent: pemanggilan kedua sebelumnya menggandakan seluruh baris
        // (dan koreksi yang sudah dibuat user ikut terduplikasi).
        if ($analysis->trainingItems()->exists()) {
            return;
        }

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

        if (! is_array($rows) || empty($rows)) {
            return;
        }

        $batch = [];
        $now = now();

        foreach ($rows as $row) {
            if (empty($row['text'])) {
                continue;
            }

            // Normalise sentiment. Analisis bertipe 'aspect' tidak menghasilkan
            // sentimen sama sekali, jadi biarkan null daripada mengarang 'neutral'
            // yang akan dihitung sebagai prediksi salah saat evaluasi.
            $sentimentLabel = null;
            if (isset($row['sentiment'])) {
                $sentimentLabel = is_array($row['sentiment'])
                    ? ($row['sentiment']['label'] ?? null)
                    : $row['sentiment'];
            }
            $confidence = $row['confidence'] ?? $row['score'] ?? 0;

            // Aspek per baris kini dikirim NLPApiService (document_aspects).
            // Keyword matching di bawah hanya cadangan untuk hasil analisis lama
            // yang tersimpan sebelum document_aspects ikut disimpan.
            $rowAspects = $row['aspects'] ?? [];
            if (empty($rowAspects) && ! empty($aspectKeywords)) {
                $textLower = strtolower($row['text']);
                foreach ($aspectKeywords as $keyword) {
                    if (preg_match("/\b".preg_quote($keyword, '/')."\b/i", $textLower)) {
                        $rowAspects[] = $keyword;
                    }
                }
            }

            $batch[] = [
                'text_analysis_id' => $analysis->id,
                'text_content' => $row['text'],
                'predicted_sentiment' => $sentimentLabel ? strtolower($sentimentLabel) : null,
                'confidence_score' => (float) $confidence,
                'detected_aspects' => json_encode(array_values(array_unique($rowAspects))),
                'is_corrected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 200) {
                TrainingItem::insert($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            TrainingItem::insert($batch);
        }
    }
}
