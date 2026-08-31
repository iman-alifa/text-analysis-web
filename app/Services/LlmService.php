<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class LlmService
{
    protected $client;
    protected $apiKey;
    protected $model;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
        ]);
        $this->apiKey = config('services.gemini.key');
        $this->model = config('services.gemini.model', 'gemini-3.5-flash');
    }

    /**
     * Generate interpretation for topic modeling results using Gemini API
     * 
     * @param array $topics Array of topics with their keywords
     * @return array Array of interpretations mapped by topic index
     */
    public function generateTopicInterpretations(array $topics)
    {
        if (empty($this->apiKey)) {
            Log::warning('GEMINI_API_KEY belum diisi di .env');
            return [];
        }

        // Prepare the prompt
        $prompt = "Anda adalah ahli linguistik dan data scientist yang ahli dalam merangkum hasil Topic Modeling. "
                . "Berikut adalah daftar topik yang diekstrak beserta kata-kata kunci teratasnya:\n\n";

        foreach ($topics as $index => $topic) {
            $keywords = implode(', ', array_slice($topic['words'], 0, 10)); // Take top 10 words
            $prompt .= "Topik ID " . $index . ": " . $keywords . "\n";
        }

        $prompt .= "\nTugas Anda: Untuk setiap Topik, berikan 1 nama label singkat (maksimal 3-4 kata) "
                 . "dan 1 kalimat singkat yang mendeskripsikan makna topik tersebut berdasarkan kata-kata kuncinya.\n"
                 . "Berikan jawaban dalam format JSON murni TANPA markdown formatting (tanpa ```json ... ```), "
                 . "dengan struktur array of objects berisi 'topic_id' (sesuai dengan Topik ID di atas), 'label', dan 'description'.\n"
                 . "Contoh output:\n[\n  {\"topic_id\": 0, \"label\": \"Harga & Promosi\", \"description\": \"Komentar pengguna terkait murahnya harga dan ketersediaan diskon.\"}\n]";

        try {
            $response = $this->client->post($this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2, // Low temperature for more deterministic/factual output
                        'response_mime_type' => 'application/json',
                    ]
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $generatedText = $result['candidates'][0]['content']['parts'][0]['text'];
                
                // Parse JSON
                $interpretations = json_decode($generatedText, true);
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($interpretations)) {
                    // Map by topic_id
                    $mapped = [];
                    foreach ($interpretations as $i => $item) {
                        $topicId = isset($item['topic_id']) ? (int)$item['topic_id'] : $i;
                        
                        // If the LLM returned 1-based index (e.g. 1 instead of 0), detect and adjust it
                        if (!array_key_exists($topicId, $topics) && array_key_exists($topicId - 1, $topics)) {
                            $topicId = $topicId - 1;
                        }
                        
                        $mapped[$topicId] = [
                            'label' => $item['label'] ?? 'Topik Tidak Diketahui',
                            'description' => $item['description'] ?? ''
                        ];
                    }
                    return $mapped;
                } else {
                    Log::error('Failed to parse Gemini JSON response: ' . json_last_error_msg());
                    Log::error('Raw Gemini Response: ' . $generatedText);
                }
            }
            
            return [];
            
        } catch (RequestException $e) {
            Log::error('Gemini API Request Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                Log::error('Gemini API Error Response: ' . $e->getResponse()->getBody());
            }
            return [];
        } catch (\Exception $e) {
            Log::error('General error during LLM generation: ' . $e->getMessage());
            return [];
        }
    }
}
