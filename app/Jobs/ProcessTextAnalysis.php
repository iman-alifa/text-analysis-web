<?php

namespace App\Jobs;

use App\Models\TextAnalysis;
use App\Models\AnalysisResult;
use App\Models\AnalysisLog;
use App\Models\CustomStopword;
use App\Models\PreprocessingConfig;
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

    public $timeout = 1800; // 30 minutes untuk data besar
    public $tries = 3;
    public $backoff = [120, 300, 600]; // 2min, 5min, 10min

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

            $textCount = count($texts);
            Log::info("Analyzing {$textCount} texts");

            // ✅ Preprocessing config (30%)
            $this->updateProgress(30, 'Menyiapkan konfigurasi preprocessing...');
            $preprocessingConfig = $this->getPreprocessingConfig();

            // ✅ Create progress callback
            $progressCallback = function($progress, $message) {
                $this->updateProgress($progress, $message);
            };

            // ✅ Perform analysis based on type (40-70%)
            $result = null;

            switch ($this->analysis->analysis_type) {
                case 'sentiment':
                    $this->updateProgress(40, 'Memulai analisis sentimen...');
                    $result = $nlpService->analyzeSentiment($texts, $preprocessingConfig, $progressCallback);
                    break;

                case 'aspect':
                    $this->updateProgress(40, 'Memulai ekstraksi aspek...');
                    $predefinedAspects = $this->getPredefinedAspects();
                    $mode = $this->getAspectMode($predefinedAspects);
                    $result = $nlpService->analyzeAspect($texts, $preprocessingConfig, $predefinedAspects, $mode, $progressCallback);
                    break;

                case 'topic':
                    $this->updateProgress(40, 'Memulai identifikasi topik...');
                    $numTopics = $this->analysis->metadata['num_topics'] ?? 5;
                    
                    // Topic modeling untuk data besar bisa lama
                    if ($textCount > 500) {
                        $this->updateProgress(45, "Memproses {$textCount} teks untuk topic modeling...");
                    }
                    
                    $result = $nlpService->analyzeTopic($texts, $preprocessingConfig, $numTopics);
                    $this->updateProgress(70, 'Topic modeling selesai');
                    break;

                case 'combined':
                    $this->updateProgress(40, 'Memulai analisis gabungan...');
                    
                    if ($textCount > 100) {
                        $this->updateProgress(42, "Memproses {$textCount} teks dengan batch processing...");
                    }
                    
                    $result = $nlpService->analyzeCombined($texts, $preprocessingConfig, $progressCallback);
                    $this->updateProgress(70, 'Analisis gabungan selesai');
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

            $duration = $this->analysis->started_at->diffInSeconds(now());
            
            AnalysisLog::createLog(
                'completed',
                $this->analysis->user_id,
                $this->analysis->id,
                'Analysis completed successfully',
                [
                    'duration' => $duration,
                    'text_count' => $textCount
                ]
            );

            Log::info("Analysis ID {$this->analysis->id} completed successfully in {$duration} seconds");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // ✅ Connection timeout - will retry
            Log::warning("Analysis ID {$this->analysis->id} connection timeout: " . $e->getMessage());

            if ($this->attempts() < $this->tries) {
                $retryDelay = $this->backoff[$this->attempts() - 1] ?? 120;
                
                $this->updateProgress(
                    $this->analysis->progress ?? 0,
                    "Koneksi terputus. Mencoba kembali dalam {$retryDelay} detik... (Percobaan {$this->attempts()}/{$this->tries})"
                );
                
                $this->release($retryDelay);
                return;
            }

            // All retries exhausted
            $this->handleFailure($e, 'Koneksi ke service analisis gagal setelah beberapa percobaan. Data terlalu besar atau service tidak merespons.');

        } catch (\Illuminate\Http\Client\RequestException $e) {
            // HTTP errors
            Log::error("Analysis ID {$this->analysis->id} request error: " . $e->getMessage());
            
            if ($this->attempts() < $this->tries) {
                $retryDelay = $this->backoff[$this->attempts() - 1] ?? 120;
                $this->updateProgress(
                    $this->analysis->progress ?? 0,
                    "Terjadi kesalahan. Mencoba kembali... (Percobaan {$this->attempts()}/{$this->tries})"
                );
                $this->release($retryDelay);
                return;
            }
            
            $this->handleFailure($e, 'Service analisis mengembalikan error. Silakan coba lagi atau hubungi administrator.');

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
        Log::error("Exception: " . get_class($e));
        Log::error("Stack trace: " . $e->getTraceAsString());

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
            [
                'error' => $errorMessage,
                'exception' => get_class($e),
                'attempts' => $this->attempts()
            ]
        );

        // Don't re-throw to prevent infinite retry
        // throw $e;
    }

    /**
     * Ambil konfigurasi preprocessing yang dipilih user, bukan nilai hardcoded.
     * Urutan: config pilihan user -> config default di database -> fallback statis.
     * Custom stopword dari admin selalu digabungkan ke config manapun.
     */
    protected function getPreprocessingConfig(): array
    {
        $fallback = [
            'case_folding' => true,
            'remove_punctuation' => true,
            'remove_numbers' => false,
            'remove_stopwords' => true,
            'stemming' => true,
            'lemmatization' => false,
            'custom_stopwords' => [],
        ];

        $config = null;
        $configId = $this->analysis->metadata['preprocessing_config_id'] ?? null;

        try {
            if ($configId) {
                $config = PreprocessingConfig::find($configId);
            }

            if (!$config) {
                $config = PreprocessingConfig::where('is_default', true)->first();
            }
        } catch (Exception $e) {
            Log::warning('Gagal memuat preprocessing config: ' . $e->getMessage());
        }

        $resolved = $config ? $config->toApiFormat() : $fallback;

        $resolved['custom_stopwords'] = array_values(array_unique(array_merge(
            $resolved['custom_stopwords'] ?? [],
            $this->getCustomStopwords()
        )));

        Log::info("Preprocessing config untuk analisis {$this->analysis->id}", [
            'config_id' => $config->id ?? null,
            'config_name' => $config->name ?? 'fallback',
            'custom_stopwords' => count($resolved['custom_stopwords']),
        ]);

        return $resolved;
    }

    /**
     * Stopword tambahan yang dikelola admin lewat halaman training.
     */
    protected function getCustomStopwords(): array
    {
        try {
            return CustomStopword::pluck('word')
                ->map(fn ($word) => strtolower(trim((string) $word)))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Exception $e) {
            Log::warning('Gagal memuat custom stopwords: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Mode ekstraksi aspek: pakai pilihan user bila ada, kalau tidak
     * tentukan dari ada/tidaknya daftar aspek yang ditentukan sendiri.
     */
    protected function getAspectMode(?array $predefinedAspects): string
    {
        $mode = $this->analysis->metadata['aspect_mode'] ?? null;

        if (in_array($mode, ['automatic', 'rule-based'], true)) {
            // Mode rule-based tanpa daftar aspek tidak ada artinya bagi Python
            if ($mode === 'rule-based' && empty($predefinedAspects)) {
                Log::warning("Analisis {$this->analysis->id}: mode rule-based tanpa predefined_aspects, fallback ke automatic");
                return 'automatic';
            }

            return $mode;
        }

        return $predefinedAspects ? 'rule-based' : 'automatic';
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
        $analysisResults = $result['results'] ?? $result;

        $data = [
            'text_analysis_id' => $this->analysis->id,
            'preprocessed_data' => null,
            'predictions' => null,
            'sentiment_distribution' => null,
            'aspect_results' => null,
            'topic_results' => null,
            'association_results' => null,
            'document_aspects' => null,
            'metrics' => null,
            'summary' => null
        ];

        $originalTexts = $this->analysis->raw_data;

        switch ($this->analysis->analysis_type) {
            case 'sentiment':
                $data['predictions'] = $this->attachOriginalTexts(
                    $analysisResults['predictions'] ?? [],
                    $originalTexts
                );
                $data['sentiment_distribution'] = $analysisResults['distribution'] ?? null;
                $data['metrics'] = $analysisResults['metrics'] ?? null;
                $data['summary'] = $analysisResults['summary'] ?? null;
                break;

            case 'aspect':
                $documentAspects = $analysisResults['document_aspects'] ?? [];

                $data['aspect_results'] = $analysisResults['aspect_sentiments'] ?? null;
                $data['document_aspects'] = $documentAspects ?: null;
                // Tanpa baris prediksi per teks, TrainingItemService tidak punya
                // apa pun untuk dipecah sehingga halaman feedback selalu kosong.
                $data['predictions'] = $this->buildAspectPredictions($originalTexts, $documentAspects);
                $data['summary'] = $analysisResults['summary'] ?? null;
                break;

            case 'topic':
                $data['topic_results'] = $analysisResults;
                $data['summary'] = $analysisResults['summary'] ?? null;
                break;

            case 'combined':
                $documentAspects = $analysisResults['aspect']['document_aspects'] ?? [];

                $data['predictions'] = $this->attachOriginalTexts(
                    $analysisResults['sentiment']['predictions'] ?? [],
                    $originalTexts,
                    $documentAspects
                );
                $data['sentiment_distribution'] = $analysisResults['sentiment']['distribution'] ?? null;
                $data['aspect_results'] = $analysisResults['aspect']['aspect_sentiments'] ?? null;
                $data['topic_results'] = $analysisResults['topic'] ?? null;
                $data['association_results'] = $analysisResults['association'] ?? null;
                $data['document_aspects'] = $documentAspects ?: null;
                $data['metrics'] = $analysisResults['sentiment']['metrics'] ?? null;
                $data['summary'] = $this->generateCombinedSummary($analysisResults);
                break;
        }

        AnalysisResult::updateOrCreate(
            ['text_analysis_id' => $this->analysis->id],
            $data
        );
    }

    /**
     * Kembalikan teks asli (sebelum preprocessing) ke setiap prediksi.
     *
     * Pemetaan memakai original_index yang dikirim NLPApiService saat batching,
     * supaya posisi tetap benar walaupun ada batch yang gagal di tengah.
     */
    protected function attachOriginalTexts(
        array $predictions,
        array $originalTexts,
        array $documentAspects = []
    ): array {
        foreach ($predictions as $position => &$prediction) {
            $index = $prediction['original_index'] ?? $position;

            if (isset($originalTexts[$index])) {
                $prediction['original_text'] = $originalTexts[$index];
                $prediction['processed_text'] = $prediction['text'] ?? null;
                $prediction['text'] = $originalTexts[$index];
            }

            if (!empty($documentAspects[$index])) {
                $prediction['aspects'] = array_values(array_unique($documentAspects[$index]));
            }
        }

        return $predictions;
    }

    /**
     * Susun baris prediksi untuk analisis aspek, yang tidak menghasilkan
     * prediksi sentimen per teks dari Python.
     */
    protected function buildAspectPredictions(array $originalTexts, array $documentAspects): array
    {
        $predictions = [];

        foreach ($originalTexts as $index => $text) {
            $predictions[] = [
                'text' => $text,
                'original_text' => $text,
                'original_index' => $index,
                'aspects' => array_values(array_unique($documentAspects[$index] ?? [])),
            ];
        }

        return $predictions;
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
        Log::error("Job permanently failed for analysis ID {$this->analysis->id}: " . $exception->getMessage());

        $this->analysis->update([
            'status' => 'failed',
            'error_message' => 'Analisis gagal setelah beberapa percobaan. ' . $exception->getMessage(),
            'completed_at' => now()
        ]);

        AnalysisLog::createLog(
            'failed',
            $this->analysis->user_id,
            $this->analysis->id,
            'Job permanently failed after all retries',
            [
                'error' => $exception->getMessage(),
                'exception' => get_class($exception)
            ]
        );
    }
}