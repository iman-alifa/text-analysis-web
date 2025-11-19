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

        foreach ($topics as $index => $topic) {
            $labels[] = 'Topik #' . ($topic['topic_id'] + 1);
            $proportions[] = round($topic['proportion'] * 100, 1);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Proporsi',
                    'data' => $proportions,
                    'backgroundColor' => array_slice($colors, 0, count($topics)),
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
        
        $maxFrequency = max(array_column($words, 'frequency'));
        
        return array_map(function($item) use ($maxFrequency) {
            return [
                'word' => $item['word'],
                'size' => 12 + (($item['frequency'] / $maxFrequency) * 20),
                'frequency' => $item['frequency']
            ];
        }, $words);
    }
}