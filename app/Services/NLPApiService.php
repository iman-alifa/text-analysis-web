<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class NLPApiService
{
    private string $apiUrl;
    private int $timeout;
    private int $batchSize;

    public function __construct()
    {
        $this->apiUrl = config('services.nlp_api.url');
        $this->timeout = config('services.nlp_api.timeout', 300);
        $this->batchSize = config('services.nlp_api.batch_size', 50);
    }

    public function testConnection(): array
    {
        try {
            $response = Http::timeout(10)->get("{$this->apiUrl}/health");
            
            if ($response->successful()) {
                return [
                    'status' => 'success',
                    'message' => 'Connected to NLP API',
                    'data' => $response->json()
                ];
            }
            
            return [
                'status' => 'error',
                'message' => 'NLP API returned error',
                'data' => $response->json()
            ];
            
        } catch (Exception $e) {
            Log::error('NLP API Connection Failed: ' . $e->getMessage());
            
            return [
                'status' => 'error',
                'message' => 'Failed to connect to NLP API',
                'error' => $e->getMessage()
            ];
        }
    }

    public function preprocessText(array $texts, array $config = []): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->apiUrl}/api/preprocess", [
                    'texts' => $texts,
                    'config' => $config
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            throw new Exception('Preprocessing failed: ' . $response->body());
            
        } catch (Exception $e) {
            Log::error('Preprocessing Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Analisis Sentimen dengan batch processing
     */
    public function analyzeSentiment(array $texts, array $config = [], $progressCallback = null): array
    {
        try {
            if (count($texts) <= $this->batchSize) {
                return $this->executeSentimentAnalysis($texts, $config);
            }

            return $this->batchSentimentAnalysis($texts, $config, $progressCallback);
            
        } catch (Exception $e) {
            Log::error('Sentiment Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function executeSentimentAnalysis(array $texts, array $config): array
    {
        $response = Http::timeout($this->timeout)
            ->retry(3, 1000)
            ->post("{$this->apiUrl}/api/analyze/sentiment", [
                'texts' => $texts,
                'preprocessing_config' => $config
            ]);

        if ($response->successful()) {
            $result = $response->json();
            
            if (!isset($result['status']) || $result['status'] !== 'success') {
                throw new Exception('Invalid response from NLP API');
            }
            
            return $result;
        }

        throw new Exception('Sentiment analysis failed: ' . $response->body());
    }

    private function batchSentimentAnalysis(array $texts, array $config, $progressCallback = null): array
    {
        $batches = array_chunk($texts, $this->batchSize);
        $totalBatches = count($batches);
        
        $allPredictions = [];
        $sentimentCounts = ['positive' => 0, 'negative' => 0, 'neutral' => 0];
        
        Log::info("Processing {$totalBatches} batches for sentiment analysis");

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;
            
            Log::info("Processing batch {$batchNumber}/{$totalBatches}");
            
            if ($progressCallback) {
                $progress = 40 + (($batchNumber / $totalBatches) * 30);
                call_user_func($progressCallback, $progress, "Menganalisis sentimen batch {$batchNumber}/{$totalBatches}...");
            }

            try {
                $result = $this->executeSentimentAnalysis($batch, $config);
                
                if (isset($result['results']['predictions'])) {
                    $allPredictions = array_merge($allPredictions, $result['results']['predictions']);
                    
                    if (isset($result['results']['distribution'])) {
                        foreach ($result['results']['distribution'] as $sentiment => $count) {
                            $sentimentCounts[$sentiment] += $count;
                        }
                    }
                }
                
                usleep(100000);
                
            } catch (Exception $e) {
                Log::error("Batch {$batchNumber} failed: " . $e->getMessage());
                throw new Exception("Gagal memproses batch {$batchNumber}: " . $e->getMessage());
            }
        }

        $totalTexts = count($allPredictions);
        $distribution = $sentimentCounts;
        
        $metrics = [
            'total_texts' => $totalTexts,
            'positive_percentage' => $totalTexts > 0 ? round(($sentimentCounts['positive'] / $totalTexts) * 100, 2) : 0,
            'negative_percentage' => $totalTexts > 0 ? round(($sentimentCounts['negative'] / $totalTexts) * 100, 2) : 0,
            'neutral_percentage' => $totalTexts > 0 ? round(($sentimentCounts['neutral'] / $totalTexts) * 100, 2) : 0,
        ];

        $summary = $this->generateSentimentSummary($metrics, $distribution);

        return [
            'status' => 'success',
            'results' => [
                'predictions' => $allPredictions,
                'distribution' => $distribution,
                'metrics' => $metrics,
                'summary' => $summary
            ]
        ];
    }

    /**
     * Ekstraksi Aspek dengan batch processing - FIXED untuk Python AspectService
     */
    public function analyzeAspect(
        array $texts,
        array $config = [],
        ?array $predefinedAspects = null,
        string $mode = 'automatic',
        $progressCallback = null
    ): array {
        try {
            if (count($texts) <= $this->batchSize) {
                return $this->executeAspectAnalysis($texts, $config, $predefinedAspects, $mode);
            }

            return $this->batchAspectAnalysis($texts, $config, $predefinedAspects, $mode, $progressCallback);
            
        } catch (Exception $e) {
            Log::error('Aspect Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function executeAspectAnalysis(
        array $texts,
        array $config,
        ?array $predefinedAspects,
        string $mode
    ): array {
        $payload = [
            'texts' => $texts,
            'preprocessing_config' => $config,
            'mode' => $mode
        ];

        if ($predefinedAspects) {
            $payload['predefined_aspects'] = $predefinedAspects;
        }

        $response = Http::timeout($this->timeout)
            ->retry(3, 1000)
            ->post("{$this->apiUrl}/api/analyze/aspect", $payload);

        if ($response->successful()) {
            $result = $response->json();
            
            if (!isset($result['status']) || $result['status'] !== 'success') {
                throw new Exception('Invalid response from NLP API');
            }
            
            return $result;
        }

        throw new Exception('Aspect analysis failed: ' . $response->body());
    }

    /**
     * Batch aspect analysis - FIXED untuk Python AspectService format
     * 
     * Python AspectService returns:
     * {
     *   'status': 'success',
     *   'results': {
     *     'aspect_sentiments': [
     *       {'aspect': 'harga', 'count': 10, 'sentiments': {'positive': 60, 'neutral': 20, 'negative': 20}}
     *     ]
     *   }
     * }
     */
    private function batchAspectAnalysis(
        array $texts,
        array $config,
        ?array $predefinedAspects,
        string $mode,
        $progressCallback = null
    ): array {
        $batches = array_chunk($texts, $this->batchSize);
        $totalBatches = count($batches);
        
        // Aggregated aspect data
        $aggregatedAspects = [];
        
        Log::info("Processing {$totalBatches} batches for aspect analysis", [
            'total_texts' => count($texts),
            'batch_size' => $this->batchSize,
            'mode' => $mode
        ]);

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;
            
            Log::info("Processing batch {$batchNumber}/{$totalBatches}");
            
            if ($progressCallback) {
                $progress = 40 + (($batchNumber / $totalBatches) * 30);
                call_user_func($progressCallback, $progress, "Mengekstrak aspek batch {$batchNumber}/{$totalBatches}...");
            }

            try {
                $result = $this->executeAspectAnalysis($batch, $config, $predefinedAspects, $mode);
                
                // Log untuk debugging
                Log::info("Batch {$batchNumber} response structure", [
                    'status' => $result['status'] ?? 'unknown',
                    'has_results' => isset($result['results']),
                    'result_keys' => isset($result['results']) ? array_keys($result['results']) : [],
                ]);
                
                if (isset($result['results']['aspect_sentiments']) && is_array($result['results']['aspect_sentiments'])) {
                    $batchAspects = $result['results']['aspect_sentiments'];
                    
                    Log::info("Batch {$batchNumber} found aspects", [
                        'count' => count($batchAspects),
                        'sample' => array_slice($batchAspects, 0, 2)
                    ]);
                    
                    // Aggregate aspects
                    foreach ($batchAspects as $aspectData) {
                        // Validate aspect data structure
                        if (!isset($aspectData['aspect']) || !isset($aspectData['count'])) {
                            Log::warning("Invalid aspect data", ['data' => $aspectData]);
                            continue;
                        }
                        
                        $aspectName = $aspectData['aspect'];
                        $count = $aspectData['count'];
                        $sentiments = $aspectData['sentiments'] ?? ['positive' => 0, 'neutral' => 0, 'negative' => 0];
                        
                        if (!isset($aggregatedAspects[$aspectName])) {
                            $aggregatedAspects[$aspectName] = [
                                'aspect' => $aspectName,
                                'count' => 0,
                                'positive' => 0,
                                'neutral' => 0,
                                'negative' => 0,
                            ];
                        }
                        
                        // Aggregate count
                        $aggregatedAspects[$aspectName]['count'] += $count;
                        
                        // sentiments dari Python adalah percentages (e.g. 60.5)
                        // Convert ke counts untuk aggregasi
                        $positiveCount = round(($sentiments['positive'] / 100) * $count);
                        $neutralCount = round(($sentiments['neutral'] / 100) * $count);
                        $negativeCount = round(($sentiments['negative'] / 100) * $count);
                        
                        $aggregatedAspects[$aspectName]['positive'] += $positiveCount;
                        $aggregatedAspects[$aspectName]['neutral'] += $neutralCount;
                        $aggregatedAspects[$aspectName]['negative'] += $negativeCount;
                    }
                } else {
                    Log::warning("Batch {$batchNumber} has no aspect_sentiments in results", [
                        'available_keys' => isset($result['results']) ? array_keys($result['results']) : []
                    ]);
                }
                
                usleep(100000);
                
            } catch (Exception $e) {
                Log::error("Batch {$batchNumber} failed: " . $e->getMessage());
                throw new Exception("Gagal memproses batch {$batchNumber}: " . $e->getMessage());
            }
        }

        Log::info("Aggregation complete", [
            'total_aspects' => count($aggregatedAspects),
            'aspects' => array_keys($aggregatedAspects)
        ]);

        // Convert to final format
        $finalAspects = [];
        foreach ($aggregatedAspects as $aspectData) {
            $total = $aspectData['count'];
            
            if ($total == 0) {
                Log::warning("Skipping aspect with zero count", ['aspect' => $aspectData['aspect']]);
                continue;
            }
            
            // Calculate percentages
            $finalAspects[] = [
                'aspect' => $aspectData['aspect'],
                'count' => $total,
                'sentiments' => [
                    'positive' => round(($aspectData['positive'] / $total) * 100, 1),
                    'neutral' => round(($aspectData['neutral'] / $total) * 100, 1),
                    'negative' => round(($aspectData['negative'] / $total) * 100, 1),
                ]
            ];
        }
        
        // Sort by count (descending)
        usort($finalAspects, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });
        
        // Generate summary
        $summary = count($finalAspects) > 0 
            ? $this->generateAspectSummary($finalAspects) 
            : 'Tidak ada aspek yang teridentifikasi dari analisis.';

        return [
            'status' => 'success',
            'results' => [
                'aspect_sentiments' => $finalAspects,
                'summary' => $summary
            ]
        ];
    }

    public function analyzeTopic(array $texts, array $config = [], int $numTopics = 5): array
    {
        try {
            $response = Http::timeout($this->timeout * 2)
                ->retry(2, 2000)
                ->post("{$this->apiUrl}/api/analyze/topic", [
                    'texts' => $texts,
                    'preprocessing_config' => $config,
                    'num_topics' => $numTopics
                ]);

            if ($response->successful()) {
                $result = $response->json();
                
                if (!isset($result['status']) || $result['status'] !== 'success') {
                    throw new Exception('Invalid response from NLP API');
                }
                
                return $result;
            }

            throw new Exception('Topic analysis failed: ' . $response->body());
            
        } catch (Exception $e) {
            Log::error('Topic Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function analyzeCombined(array $texts, array $config = [], $progressCallback = null): array
    {
        try {
            Log::info("Starting combined analysis for " . count($texts) . " texts");
            
            if (count($texts) <= $this->batchSize) {
                return $this->executeCombinedAnalysis($texts, $config);
            }

            $sentimentResult = $this->analyzeSentiment($texts, $config, function($progress, $message) use ($progressCallback) {
                if ($progressCallback) {
                    call_user_func($progressCallback, 40 + ($progress - 40) * 0.33, $message);
                }
            });

            $aspectResult = $this->analyzeAspect($texts, $config, null, 'automatic', function($progress, $message) use ($progressCallback) {
                if ($progressCallback) {
                    call_user_func($progressCallback, 50 + ($progress - 40) * 0.33, $message);
                }
            });

            if ($progressCallback) {
                call_user_func($progressCallback, 60, "Mengidentifikasi topik...");
            }
            
            $topicResult = $this->analyzeTopic($texts, $config);

            return [
                'status' => 'success',
                'results' => [
                    'sentiment' => $sentimentResult['results'] ?? $sentimentResult,
                    'aspect' => $aspectResult['results'] ?? $aspectResult,
                    'topic' => $topicResult['results'] ?? $topicResult
                ]
            ];
            
        } catch (Exception $e) {
            Log::error('Combined Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    private function executeCombinedAnalysis(array $texts, array $config): array
    {
        $response = Http::timeout($this->timeout * 2)
            ->retry(2, 2000)
            ->post("{$this->apiUrl}/api/analyze/combined", [
                'texts' => $texts,
                'preprocessing_config' => $config
            ]);

        if ($response->successful()) {
            $result = $response->json();
            
            if (!isset($result['status']) || $result['status'] !== 'success') {
                throw new Exception('Invalid response from NLP API');
            }
            
            return $result;
        }

        throw new Exception('Combined analysis failed: ' . $response->body());
    }

    private function generateSentimentSummary(array $metrics, array $distribution): string
    {
        $dominant = array_keys($distribution, max($distribution))[0];
        $percentage = $metrics["{$dominant}_percentage"];
        
        return "Dari {$metrics['total_texts']} teks yang dianalisis, mayoritas ({$percentage}%) memiliki sentimen {$dominant}.";
    }

    private function generateAspectSummary(array $aspectResults): string
    {
        if (empty($aspectResults)) {
            return 'Tidak ada aspek yang teridentifikasi.';
        }
        
        $topAspects = array_slice($aspectResults, 0, 3);
        $aspectNames = array_column($topAspects, 'aspect');
        
        $totalMentions = array_sum(array_column($aspectResults, 'count'));
        
        return "Teridentifikasi " . count($aspectResults) . " aspek dari {$totalMentions} mentions. " .
               "Aspek utama: " . implode(', ', $aspectNames) . ".";
    }
}