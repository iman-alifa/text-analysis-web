<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class NLPApiService
{
    private string $apiUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiUrl = config('services.nlp_api.url');
        $this->timeout = config('services.nlp_api.timeout');
    }

    /**
     * Test koneksi ke Python API
     */
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

    /**
     * Preprocessing text
     */
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
     * Analisis Sentimen
     */
    public function analyzeSentiment(array $texts, array $config = []): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->apiUrl}/api/analyze/sentiment", [
                    'texts' => $texts,
                    'preprocessing_config' => $config
                ]);

            if ($response->successful()) {
                $result = $response->json();
                
                // Validate response structure
                if (!isset($result['status']) || $result['status'] !== 'success') {
                    throw new Exception('Invalid response from NLP API');
                }
                
                return $result;
            }

            throw new Exception('Sentiment analysis failed: ' . $response->body());
            
        } catch (Exception $e) {
            Log::error('Sentiment Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Ekstraksi Aspek
     */
    public function analyzeAspect(
        array $texts,
        array $config = [],
        ?array $predefinedAspects = null,
        string $mode = 'automatic'
    ): array {
        try {
            $payload = [
                'texts' => $texts,
                'preprocessing_config' => $config,
                'mode' => $mode
            ];

            if ($predefinedAspects) {
                $payload['predefined_aspects'] = $predefinedAspects;
            }

            $response = Http::timeout($this->timeout)
                ->post("{$this->apiUrl}/api/analyze/aspect", $payload);

            if ($response->successful()) {
                $result = $response->json();
                
                if (!isset($result['status']) || $result['status'] !== 'success') {
                    throw new Exception('Invalid response from NLP API');
                }
                
                return $result;
            }

            throw new Exception('Aspect analysis failed: ' . $response->body());
            
        } catch (Exception $e) {
            Log::error('Aspect Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Identifikasi Topik
     */
    public function analyzeTopic(array $texts, array $config = [], int $numTopics = 5): array
    {
        try {
            $response = Http::timeout($this->timeout)
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

    /**
     * Combined Analysis (Sentiment + Aspect + Topic)
     */
    public function analyzeCombined(array $texts, array $config = []): array
    {
        try {
            $response = Http::timeout($this->timeout)
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
            
        } catch (Exception $e) {
            Log::error('Combined Analysis Error: ' . $e->getMessage());
            throw $e;
        }
    }
}