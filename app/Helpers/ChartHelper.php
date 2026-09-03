<?php

namespace App\Helpers;

class ChartHelper
{
    /**
     * Generate sentiment distribution data for Chart.js
     */
    public static function prepareSentimentChartData(array $distribution): array
    {
        return [
            'labels' => ['Positif', 'Netral', 'Negatif'],
            'datasets' => [
                [
                    'data' => [
                        $distribution['positive'] ?? 0,
                        $distribution['neutral'] ?? 0,
                        $distribution['negative'] ?? 0,
                    ],
                    'backgroundColor' => [
                        'rgba(16, 185, 129, 0.8)',
                        'rgba(107, 114, 128, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                    ],
                    'borderColor' => [
                        'rgb(16, 185, 129)',
                        'rgb(107, 114, 128)',
                        'rgb(239, 68, 68)',
                    ],
                    'borderWidth' => 2,
                ]
            ]
        ];
    }

    /**
     * Generate aspect chart data
     */
    public static function prepareAspectChartData(array $aspects): array
    {
        $labels = [];
        $positive = [];
        $neutral = [];
        $negative = [];

        foreach ($aspects as $aspect) {
            $labels[] = ucfirst($aspect['aspect']);
            $positive[] = $aspect['sentiments']['positive'] ?? 0;
            $neutral[] = $aspect['sentiments']['neutral'] ?? 0;
            $negative[] = $aspect['sentiments']['negative'] ?? 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Positif',
                    'data' => $positive,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
                ],
                [
                    'label' => 'Netral',
                    'data' => $neutral,
                    'backgroundColor' => 'rgba(107, 114, 128, 0.8)',
                ],
                [
                    'label' => 'Negatif',
                    'data' => $negative,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.8)',
                ],
            ]
        ];
    }

    /**
     * Susun data asosiasi aspek-topik untuk halaman hasil.
     *
     * PMI dihitung di NLP API (AssociationService); crosstab dihitung di sini
     * dari document_aspects + document_topics yang sudah tersimpan, karena
     * keduanya murni pencacahan dokumen, bukan inference model.
     *
     * Mengembalikan null kalau data aslinya tidak ada — pemanggil wajib
     * menampilkan keadaan kosong, bukan angka contoh.
     */
    public static function prepareAssociationData(
        ?array $association,
        array $documentAspects,
        ?array $topicResults
    ): ?array {
        if (empty($association['heatmap_matrix']) || empty($topicResults['topics'])) {
            return null;
        }

        $documentTopics = $topicResults['document_topics'] ?? [];

        if (empty($documentTopics)) {
            return null;
        }

        $interpretation = $topicResults['interpretation'] ?? [];

        // Urutan topik menentukan urutan kolom pada kedua tabel
        $topicIds = [];
        $labels = [];
        $descriptions = [];

        foreach ($topicResults['topics'] as $index => $topic) {
            $topicId = $topic['topic_id'] ?? $index;

            if ($topicId < 0) {
                continue; // outlier BERTopic tidak punya skor PMI
            }

            $topicIds[] = $topicId;
            $labels[] = $interpretation[$topicId]['label']
                ?? $topic['topic_label']
                ?? ('Topik #' . ($topicId + 1));
            $descriptions[] = implode(', ', array_slice($topic['words'] ?? $topic['keywords'] ?? [], 0, 3));
        }

        if (empty($topicIds)) {
            return null;
        }

        // Aspek yang lolos filter min_mentions di sisi Python
        $aspects = array_values(array_filter(array_map(
            fn ($row) => $row['aspect'] ?? null,
            $association['heatmap_matrix']
        )));

        if (empty($aspects)) {
            return null;
        }

        $counts = [];
        foreach ($aspects as $aspect) {
            $counts[$aspect] = array_fill_keys($topicIds, 0);
        }

        foreach ($documentAspects as $documentIndex => $documentAspectList) {
            $topicId = $documentTopics[$documentIndex] ?? -1;

            if (!in_array($topicId, $topicIds, true)) {
                continue;
            }

            foreach (array_unique((array) $documentAspectList) as $aspect) {
                $aspect = strtolower(trim((string) $aspect));

                if (isset($counts[$aspect])) {
                    $counts[$aspect][$topicId]++;
                }
            }
        }

        $crosstab = [];
        foreach ($counts as $aspect => $perTopic) {
            $mentions = array_sum($perTopic);

            if ($mentions === 0) {
                continue;
            }

            $crosstab[] = [
                'aspect' => ucfirst($aspect),
                'mentions' => $mentions,
                'topics' => array_map(
                    fn ($count) => (int) round(($count / $mentions) * 100),
                    array_values($perTopic)
                ),
            ];
        }

        usort($crosstab, fn ($a, $b) => $b['mentions'] <=> $a['mentions']);

        $pmi = [];
        foreach ($association['heatmap_matrix'] as $row) {
            $scores = [];

            foreach ($topicIds as $topicId) {
                $scores[] = (float) ($row["topic_{$topicId}"] ?? 0);
            }

            $pmi[] = [
                'aspect' => ucfirst($row['aspect'] ?? '-'),
                'scores' => $scores,
            ];
        }

        usort($pmi, fn ($a, $b) => max($b['scores']) <=> max($a['scores']));

        if (empty($crosstab)) {
            return null;
        }

        return [
            'topics_label' => $labels,
            'topics_desc' => $descriptions,
            'crosstab' => array_slice($crosstab, 0, 10),
            'pmi' => array_slice($pmi, 0, 10),
        ];
    }

    /**
     * Generate topic chart data
     */
    public static function prepareTopicChartData(array $topics): array
    {
        $labels = [];
        $proportions = [];
        $colors = [
            'rgba(59, 130, 246, 0.8)',
            'rgba(16, 185, 129, 0.8)',
            'rgba(249, 115, 22, 0.8)',
            'rgba(168, 85, 247, 0.8)',
            'rgba(236, 72, 153, 0.8)',
        ];

        $backgrounds = [];

        foreach ($topics as $index => $topic) {
            $labels[] = 'Topik #' . (($topic['topic_id'] ?? $index) + 1);
            $proportions[] = round(($topic['proportion'] ?? 0) * 100, 1);
            // Warna diputar, bukan dipotong: mode otomatis bisa menghasilkan
            // sampai 20 topik sementara paletnya hanya lima, sehingga topik
            // keenam dan seterusnya dulu tidak mendapat warna sama sekali.
            $backgrounds[] = $colors[$index % count($colors)];
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Proporsi',
                    'data' => $proportions,
                    'backgroundColor' => $backgrounds,
                ]
            ]
        ];
    }

    /**
     * Generate word cloud data
     */
    public static function prepareWordCloudData(array $wordFrequencies, int $limit = 30): array
    {
        $words = array_slice($wordFrequencies, 0, $limit);
        
        // max() melempar galat pada array kosong, dan pembagian dengan 0
        // menghasilkan INF - keduanya membuat halaman hasil gagal dirender
        // untuk korpus kecil yang tidak menghasilkan frekuensi kata.
        if (empty($words)) {
            return [];
        }

        $maxFrequency = max(array_column($words, 'frequency')) ?: 1;
        
        return array_map(function($item) use ($maxFrequency) {
            return [
                'word' => $item['word'],
                'size' => 12 + (($item['frequency'] / $maxFrequency) * 20),
                'frequency' => $item['frequency']
            ];
        }, $words);
    }
}