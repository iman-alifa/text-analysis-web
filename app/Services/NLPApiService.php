<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class NLPApiService
{
    /** Minimal sampel yang diterima endpoint /api/retrain/* di sisi Python */
    public const MIN_RETRAIN_SAMPLES = 10;

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
        // preserve_keys supaya setiap prediksi bisa dipetakan balik ke posisi
        // teks aslinya, walaupun ada batch yang gagal di tengah jalan.
        $batches = array_chunk($texts, $this->batchSize, true);
        $totalBatches = count($batches);

        $allPredictions = [];
        $failedBatches = [];

        Log::info("Processing {$totalBatches} batches for sentiment analysis");

        foreach ($batches as $index => $batch) {
            $batchNumber = $index + 1;
            $originalIndexes = array_keys($batch);

            Log::info("Processing batch {$batchNumber}/{$totalBatches}");

            if ($progressCallback) {
                $progress = 40 + (($batchNumber / $totalBatches) * 30);
                call_user_func($progressCallback, $progress, "Menganalisis sentimen batch {$batchNumber}/{$totalBatches}...");
            }

            try {
                $result = $this->executeSentimentAnalysis(array_values($batch), $config);

                $predictions = $result['results']['predictions'] ?? [];

                foreach ($predictions as $position => $prediction) {
                    if (isset($originalIndexes[$position])) {
                        $prediction['original_index'] = $originalIndexes[$position];
                    }
                    $allPredictions[] = $prediction;
                }

                usleep(100000);

            } catch (Exception $e) {
                // Satu batch gagal tidak boleh membatalkan seluruh analisis.
                // Batch yang gagal dicatat dan dilaporkan lewat metrics.
                Log::error("Batch {$batchNumber} failed: " . $e->getMessage());
                $failedBatches[] = [
                    'batch' => $batchNumber,
                    'texts' => count($batch),
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($allPredictions)) {
            throw new Exception(
                'Analisis sentimen gagal: seluruh ' . $totalBatches . ' batch tidak berhasil diproses.'
            );
        }

        $distribution = $this->calculateSentimentDistribution($allPredictions);
        $metrics = $this->calculateSentimentMetrics($allPredictions, $distribution, $totalBatches, $failedBatches);
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
     * Hitung distribusi sentimen sebagai PERSENTASE, sama seperti yang
     * dikembalikan endpoint Python untuk request tunggal.
     *
     * Sebelumnya kode ini menjumlahkan persentase antar-batch seolah-olah count,
     * sehingga dataset >batch_size menghasilkan total ratusan persen.
     */
    private function calculateSentimentDistribution(array $predictions): array
    {
        $counts = ['positive' => 0, 'neutral' => 0, 'negative' => 0];

        foreach ($predictions as $prediction) {
            $label = strtolower((string) ($prediction['sentiment'] ?? 'neutral'));

            if (array_key_exists($label, $counts)) {
                $counts[$label]++;
            }
        }

        $total = max(1, array_sum($counts));

        return [
            'positive' => round(($counts['positive'] / $total) * 100, 2),
            'neutral'  => round(($counts['neutral'] / $total) * 100, 2),
            'negative' => round(($counts['negative'] / $total) * 100, 2),
        ];
    }

    private function calculateSentimentMetrics(
        array $predictions,
        array $distribution,
        int $totalBatches = 0,
        array $failedBatches = []
    ): array {
        $confidences = array_filter(
            array_map(fn ($prediction) => $prediction['confidence'] ?? null, $predictions),
            fn ($confidence) => $confidence !== null
        );

        $metrics = [
            'total_texts' => count($predictions),
            'total_analyzed' => count($predictions),
            'positive_percentage' => $distribution['positive'],
            'neutral_percentage' => $distribution['neutral'],
            'negative_percentage' => $distribution['negative'],
            'avg_confidence' => $confidences ? round(array_sum($confidences) / count($confidences), 4) : 0.0,
            'min_confidence' => $confidences ? round(min($confidences), 4) : 0.0,
            'max_confidence' => $confidences ? round(max($confidences), 4) : 0.0,
        ];

        if (!empty($failedBatches)) {
            $metrics['failed_batches'] = $failedBatches;
            $metrics['batch_summary'] = sprintf(
                '%d dari %d batch berhasil diproses.',
                $totalBatches - count($failedBatches),
                $totalBatches
            );
        }

        return $metrics;
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
        $batches = array_chunk($texts, $this->batchSize, true);
        $totalBatches = count($batches);
        
        // Aggregated aspect data
        $aggregatedAspects = [];
        // Aspek per dokumen dari Python, dipetakan ke indeks teks aslinya.
        // Dipakai untuk mengisi training_items tanpa menebak lewat keyword.
        $documentAspects = array_fill(0, count($texts), []);
        $failedBatches = [];
        
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

            $originalIndexes = array_keys($batch);

            try {
                $result = $this->executeAspectAnalysis(array_values($batch), $config, $predefinedAspects, $mode);

                if (isset($result['results']['document_aspects']) && is_array($result['results']['document_aspects'])) {
                    foreach ($result['results']['document_aspects'] as $position => $aspectsOfDocument) {
                        if (isset($originalIndexes[$position])) {
                            $documentAspects[$originalIndexes[$position]] = array_values((array) $aspectsOfDocument);
                        }
                    }
                }
                
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
                $failedBatches[] = [
                    'batch' => $batchNumber,
                    'texts' => count($batch),
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($aggregatedAspects) && count($failedBatches) === $totalBatches) {
            throw new Exception(
                'Ekstraksi aspek gagal: seluruh ' . $totalBatches . ' batch tidak berhasil diproses.'
            );
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

        $results = [
            'aspect_sentiments' => $finalAspects,
            'document_aspects' => $documentAspects,
            'summary' => $summary
        ];

        if (!empty($failedBatches)) {
            $results['failed_batches'] = $failedBatches;
            $results['batch_summary'] = sprintf(
                '%d dari %d batch berhasil diproses.',
                $totalBatches - count($failedBatches),
                $totalBatches
            );
        }

        return [
            'status' => 'success',
            'results' => $results
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

            $sentiment = $sentimentResult['results'] ?? $sentimentResult;
            $aspect = $aspectResult['results'] ?? $aspectResult;
            $topic = $topicResult['results'] ?? $topicResult;

            // Endpoint /api/analyze/combined menghitung PMI aspek-topik sendiri,
            // tapi jalur batch ini memanggil ketiga analisis terpisah. Jadi PMI
            // diminta lewat endpoint association agar hasilnya tetap ada.
            if ($progressCallback) {
                call_user_func($progressCallback, 68, 'Menghitung asosiasi aspek-topik...');
            }

            $association = $this->analyzeAssociation(
                $aspect['document_aspects'] ?? [],
                $topic['document_topics'] ?? [],
                (int) ($topic['num_topics'] ?? 0)
            );

            return [
                'status' => 'success',
                'results' => [
                    'sentiment' => $sentiment,
                    'aspect' => $aspect,
                    'topic' => $topic,
                    'association' => $association
                ]
            ];
            
        } catch (Exception $e) {
            Log::error('Combined Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Kirim data hasil koreksi user untuk melatih ulang model sentimen.
     * Payload mengikuti SentimentRetrainRequest: [{text, label}], minimal 10 sampel.
     */
    public function retrainSentiment(array $trainingData, int $epochs = 3, float $learningRate = 0.00002): array
    {
        return $this->executeRetrain('sentiment', $trainingData, $epochs, $learningRate);
    }

    /**
     * Kirim data hasil koreksi user untuk melatih ulang model ekstraksi aspek.
     * Payload mengikuti AspectRetrainRequest: [{text, aspects: []}], minimal 10 sampel.
     */
    public function retrainAspect(array $trainingData, int $epochs = 3, float $learningRate = 0.00002): array
    {
        return $this->executeRetrain('aspect', $trainingData, $epochs, $learningRate);
    }

    private function executeRetrain(
        string $type,
        array $trainingData,
        int $epochs,
        float $learningRate
    ): array {
        if (count($trainingData) < self::MIN_RETRAIN_SAMPLES) {
            throw new Exception(sprintf(
                'Data training %s belum cukup: %d sampel, minimal %d.',
                $type,
                count($trainingData),
                self::MIN_RETRAIN_SAMPLES
            ));
        }

        Log::info("Mengirim {$type} retraining ke NLP API", [
            'samples' => count($trainingData),
            'epochs' => $epochs,
        ]);

        // Fine-tuning jauh lebih lama dari inference, jadi timeout digandakan.
        $response = Http::timeout($this->timeout * 2)
            ->post("{$this->apiUrl}/api/retrain/{$type}", [
                'training_data' => array_values($trainingData),
                'epochs' => $epochs,
                'learning_rate' => $learningRate,
            ]);

        if ($response->successful()) {
            $result = $response->json();

            if (($result['status'] ?? null) !== 'success') {
                throw new Exception("Retraining {$type} ditolak NLP API: " . $response->body());
            }

            return $result['results'] ?? [];
        }

        throw new Exception("Retraining {$type} gagal: " . $response->body());
    }

    /**
     * Hitung asosiasi aspek-topik (PMI) untuk hasil yang sudah dianalisis.
     * Mengembalikan null (bukan exception) kalau data belum lengkap atau
     * endpoint tidak tersedia — asosiasi bersifat pelengkap, bukan inti analisis.
     */
    public function analyzeAssociation(
        array $documentAspects,
        array $documentTopics,
        int $numTopics,
        int $minMentions = 3
    ): ?array {
        if (empty($documentAspects) || empty($documentTopics) || $numTopics <= 0) {
            Log::info('Association analysis dilewati: document_aspects/document_topics tidak lengkap');
            return null;
        }

        if (count($documentAspects) !== count($documentTopics)) {
            Log::warning('Association analysis dilewati: jumlah dokumen tidak sama', [
                'aspects' => count($documentAspects),
                'topics' => count($documentTopics),
            ]);
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->apiUrl}/api/analyze/association", [
                    'document_aspects' => array_values($documentAspects),
                    'document_topics' => array_values($documentTopics),
                    'num_topics' => $numTopics,
                    'min_mentions' => $minMentions,
                ]);

            if ($response->successful()) {
                return $response->json()['results'] ?? null;
            }

            Log::warning('Association analysis failed: ' . $response->body());
            return null;

        } catch (Exception $e) {
            Log::warning('Association analysis error: ' . $e->getMessage());
            return null;
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