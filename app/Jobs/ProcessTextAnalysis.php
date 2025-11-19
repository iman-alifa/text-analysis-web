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

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    protected $analysis;

    /**
     * Create a new job instance.
     */
    public function __construct(TextAnalysis $analysis)
    {
        $this->analysis = $analysis;
    }

    /**
     * Execute the job.
     */
    public function handle(NLPApiService $nlpService): void
    {
        try {
            Log::info("Processing analysis ID: {$this->analysis->id}");

            // Update status to processing
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

            // Get texts from raw_data
            $texts = $this->analysis->raw_data;
            
            if (empty($texts)) {
                throw new Exception('No texts to analyze');
            }

            // Get preprocessing config if specified
            $preprocessingConfig = $this->getPreprocessingConfig();

            // Perform analysis based on type
            $result = null;

            switch ($this->analysis->analysis_type) {
                case 'sentiment':
                    $result = $nlpService->analyzeSentiment($texts, $preprocessingConfig);
                    break;

                case 'aspect':
                    $predefinedAspects = $this->getPredefinedAspects();
                    $mode = $predefinedAspects ? 'rule-based' : 'automatic';
                    $result = $nlpService->analyzeAspect($texts, $preprocessingConfig, $predefinedAspects, $mode);
                    break;

                case 'topic':
                    $numTopics = $this->analysis->metadata['num_topics'] ?? 5;
                    $result = $nlpService->analyzeTopic($texts, $preprocessingConfig, $numTopics);
                    break;

                case 'combined':
                    $result = $nlpService->analyzeCombined($texts, $preprocessingConfig);
                    break;

                default:
                    throw new Exception('Invalid analysis type');
            }

            // Save results
            $this->saveResults($result);

            // Update analysis status
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
                    'duration' => $this->analysis->started_at->diffInSeconds($this->analysis->completed_at)
                ]
            );

            Log::info("Analysis ID {$this->analysis->id} completed successfully");

        } catch (Exception $e) {
            Log::error("Analysis ID {$this->analysis->id} failed: " . $e->getMessage());

            $this->analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now()
            ]);

            AnalysisLog::createLog(
                'failed',
                $this->analysis->user_id,
                $this->analysis->id,
                'Analysis failed',
                ['error' => $e->getMessage()]
            );

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Get preprocessing configuration
     */
    protected function getPreprocessingConfig(): array
    {
        // If preprocessing_config_id is set, load from database
        // For now, return default config
        return [
            'case_folding' => true,
            'remove_punctuation' => true,
            'remove_numbers' => false,
            'remove_stopwords' => true,
            'stemming' => true,
            'lemmatization' => false
        ];
    }

    /**
     * Get predefined aspects for aspect analysis
     */
    protected function getPredefinedAspects(): ?array
    {
        // Check if aspects are defined in metadata
        if (isset($this->analysis->metadata['predefined_aspects'])) {
            return $this->analysis->metadata['predefined_aspects'];
        }

        return null;
    }

    
    /**
     * Save analysis results to database
     */
    protected function saveResults(array $result): void
    {
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

        // Get original texts
        $originalTexts = $this->analysis->raw_data;

        // Handle different analysis types
        switch ($this->analysis->analysis_type) {
            case 'sentiment':
                // Map predictions with original texts
                if (isset($analysisResults['predictions'])) {
                    $predictions = $analysisResults['predictions'];
                    
                    // Replace preprocessed text with original text
                    foreach ($predictions as $index => &$prediction) {
                        if (isset($originalTexts[$index])) {
                            $prediction['original_text'] = $originalTexts[$index];
                            $prediction['processed_text'] = $prediction['text']; // Save processed version
                            $prediction['text'] = $originalTexts[$index]; // Show original in display
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
                // Map predictions with original texts for combined analysis
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
                
                // Generate combined summary
                $data['summary'] = $this->generateCombinedSummary($analysisResults);
                break;
        }

        // Create or update result
        AnalysisResult::updateOrCreate(
            ['text_analysis_id' => $this->analysis->id],
            $data
        );
    }

    /**
     * Generate combined summary
     */
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

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error("Job failed for analysis ID {$this->analysis->id}: " . $exception->getMessage());

        $this->analysis->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage()
        ]);

        AnalysisLog::createLog(
            'failed',
            $this->analysis->user_id,
            $this->analysis->id,
            'Job failed',
            ['error' => $exception->getMessage()]
        );
    }
}