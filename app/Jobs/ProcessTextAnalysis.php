<?php

namespace App\Jobs;

use App\Models\TextAnalysis;
use App\Models\AnalysisResult;
use App\Models\AnalysisLog;
use App\Services\NLPApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessTextAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $backoff = [60, 120, 300]; // Retry delays

    protected $analysis;

    public function __construct(TextAnalysis $analysis)
    {
        $this->analysis = $analysis;
    }

    public function handle(NLPApiService $nlpService): void
    {
        try {
            Log::info("Processing analysis ID: {$this->analysis->id}");

            // ✅ Update status to processing (10%)
            $this->updateProgress(10, 'Memulai analisis...');
            $this->analysis->update([
                'status' => 'processing',
                'started_at' => now()
            ]);

            AnalysisLog::createLog(
                'started',
                $this->analysis->user_id,
                $this->analysis->id,
                'Analysis processing started'
            );

            // ✅ Get texts (20%)
            $this->updateProgress(20, 'Memuat data teks...');
            $texts = $this->analysis->raw_data;
            
            if (empty($texts)) {
                throw new Exception('No texts to analyze');
            }

            // ✅ Preprocessing config (30%)
            $this->updateProgress(30, 'Menyiapkan konfigurasi preprocessing...');
            $preprocessingConfig = $this->getPreprocessingConfig();

            // ✅ Perform analysis based on type (40-70%)
            $result = null;

            switch ($this->analysis->analysis_type) {
                case 'sentiment':
                    $this->updateProgress(40, 'Melakukan preprocessing teks...');
                    $this->updateProgress(60, 'Menganalisis sentimen...');
                    $result = $nlpService->analyzeSentiment($texts, $preprocessingConfig);
                    break;

                case 'aspect':
                    $this->updateProgress(40, 'Melakukan preprocessing teks...');
                    $predefinedAspects = $this->getPredefinedAspects();
                    $mode = $predefinedAspects ? 'rule-based' : 'automatic';
                    $this->updateProgress(60, 'Mengekstraksi aspek dan sentimen...');
                    $result = $nlpService->analyzeAspect($texts, $preprocessingConfig, $predefinedAspects, $mode);
                    break;

                case 'topic':
                    $this->updateProgress(40, 'Melakukan preprocessing teks...');
                    $numTopics = $this->analysis->metadata['num_topics'] ?? 5;
                    $this->updateProgress(60, 'Mengidentifikasi topik...');
                    $result = $nlpService->analyzeTopic($texts, $preprocessingConfig, $numTopics);
                    break;

                case 'combined':
                    $this->updateProgress(40, 'Melakukan preprocessing teks...');
                    $this->updateProgress(50, 'Menganalisis sentimen...');
                    $this->updateProgress(60, 'Mengekstraksi aspek...');
                    $this->updateProgress(70, 'Mengidentifikasi topik...');
                    $result = $nlpService->analyzeCombined($texts, $preprocessingConfig);
                    break;

                default:
                    throw new Exception('Invalid analysis type');
            }

            // ✅ Save results (80%)
            $this->updateProgress(80, 'Menyimpan hasil analisis...');
            $this->saveResults($result);

            // ✅ Generate visualizations (90%)
            $this->updateProgress(90, 'Menghasilkan visualisasi...');

            // ✅ Complete (100%)
            $this->updateProgress(100, 'Analisis selesai!');
            $this->analysis->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);

            AnalysisLog::createLog(
                'completed',
                $this->analysis->user_id,
                $this->analysis->id,
                'Analysis completed successfully',
                [
                    'duration' => $this->analysis->started_at->diffInSeconds(now())
                ]
            );

            Log::info("Analysis ID {$this->analysis->id} completed successfully");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // ✅ Connection timeout - will retry
            Log::warning("Analysis ID {$this->analysis->id} connection timeout: " . $e->getMessage());

            if ($this->attempts() < $this->tries) {
                $retryDelay = $this->backoff[$this->attempts() - 1] ?? 60;
                
                $this->updateProgress(
                    $this->analysis->progress ?? 0,
                    "Koneksi terputus. Mencoba kembali dalam {$retryDelay} detik... (Percobaan {$this->attempts()}/{$this->tries})"
                );
                
                $this->release($retryDelay);
                return;
            }

            // All retries exhausted
            $this->handleFailure($e, 'Koneksi ke service analisis gagal setelah beberapa percobaan');

        } catch (Exception $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * ✅ Update progress dengan simpan ke database
     */
    private function updateProgress(int $progress, string $step): void
    {
        $this->analysis->updateProgress($progress, $step);
        Log::info("Analysis {$this->analysis->id}: {$progress}% - {$step}");
    }

    /**
     * ✅ Handle failure
     */
    private function handleFailure(Exception $e, string $customMessage = null): void
    {
        $errorMessage = $customMessage ?? $e->getMessage();
        
        Log::error("Analysis ID {$this->analysis->id} failed: " . $errorMessage);

        $this->analysis->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now()
        ]);

        AnalysisLog::createLog(
            'failed',
            $this->analysis->user_id,
            $this->analysis->id,
            'Analysis failed',
            ['error' => $errorMessage]
        );

        throw $e;
    }

    protected function getPreprocessingConfig(): array
    {
        return [
            'case_folding' => true,
            'remove_punctuation' => true,
            'remove_numbers' => false,
            'remove_stopwords' => true,
            'stemming' => true,
            'lemmatization' => false
        ];
    }

    protected function getPredefinedAspects(): ?array
    {
        if (isset($this->analysis->metadata['predefined_aspects'])) {
            return $this->analysis->metadata['predefined_aspects'];
        }
        return null;
    }

    protected function saveResults(array $result): void
    {
        // [Keep your existing implementation]
        $analysisResults = $result['results'] ?? $result;

        $data = [
            'text_analysis_id' => $this->analysis->id,
            'preprocessed_data' => null,
            'predictions' => null,
            'sentiment_distribution' => null,
            'aspect_results' => null,
            'topic_results' => null,
            'metrics' => null,
            'summary' => null
        ];

        $originalTexts = $this->analysis->raw_data;

        switch ($this->analysis->analysis_type) {
            case 'sentiment':
                if (isset($analysisResults['predictions'])) {
                    $predictions = $analysisResults['predictions'];
                    
                    foreach ($predictions as $index => &$prediction) {
                        if (isset($originalTexts[$index])) {
                            $prediction['original_text'] = $originalTexts[$index];
                            $prediction['processed_text'] = $prediction['text'];
                            $prediction['text'] = $originalTexts[$index];
                        }
                    }
                    
                    $data['predictions'] = $predictions;
                }
                
                $data['sentiment_distribution'] = $analysisResults['distribution'] ?? null;
                $data['metrics'] = $analysisResults['metrics'] ?? null;
                $data['summary'] = $analysisResults['summary'] ?? null;
                break;

            case 'aspect':
                $data['aspect_results'] = $analysisResults['aspect_sentiments'] ?? null;
                $data['summary'] = $analysisResults['summary'] ?? null;
                break;

            case 'topic':
                $data['topic_results'] = $analysisResults;
                $data['summary'] = $analysisResults['summary'] ?? null;
                break;

            case 'combined':
                if (isset($analysisResults['sentiment']['predictions'])) {
                    $predictions = $analysisResults['sentiment']['predictions'];
                    
                    foreach ($predictions as $index => &$prediction) {
                        if (isset($originalTexts[$index])) {
                            $prediction['original_text'] = $originalTexts[$index];
                            $prediction['processed_text'] = $prediction['text'];
                            $prediction['text'] = $originalTexts[$index];
                        }
                    }
                    
                    $data['predictions'] = $predictions;
                }
                
                $data['sentiment_distribution'] = $analysisResults['sentiment']['distribution'] ?? null;
                $data['aspect_results'] = $analysisResults['aspect']['aspect_sentiments'] ?? null;
                $data['topic_results'] = $analysisResults['topic'] ?? null;
                $data['metrics'] = $analysisResults['sentiment']['metrics'] ?? null;
                $data['summary'] = $this->generateCombinedSummary($analysisResults);
                break;
        }

        AnalysisResult::updateOrCreate(
            ['text_analysis_id' => $this->analysis->id],
            $data
        );
    }

    protected function generateCombinedSummary(array $results): string
    {
        $summary = [];

        if (isset($results['sentiment']['summary'])) {
            $summary[] = $results['sentiment']['summary'];
        }

        if (isset($results['aspect']['summary'])) {
            $summary[] = $results['aspect']['summary'];
        }

        if (isset($results['topic']['summary'])) {
            $summary[] = $results['topic']['summary'];
        }

        return implode(' ', $summary);
    }

    public function failed(Exception $exception): void
    {
        Log::error("Job failed for analysis ID {$this->analysis->id}: " . $exception->getMessage());

        $this->analysis->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'completed_at' => now()
        ]);

        AnalysisLog::createLog(
            'failed',
            $this->analysis->user_id,
            $this->analysis->id,
            'Job permanently failed',
            ['error' => $exception->getMessage()]
        );
    }
}