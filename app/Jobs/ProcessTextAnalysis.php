<?php

namespace App\Jobs;

use App\Models\AnalysisLog;
use App\Models\AnalysisResult;
use App\Models\TextAnalysis;
use App\Services\NLPApiService;
use App\Services\PreprocessingConfigResolver;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTextAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Versi pipeline analisis. Dinaikkan setiap kali perhitungan yang tersimpan
     * berubah maknanya, sehingga analisis lama bisa dikenali dan diproses ulang
     * lewat `analysis:reprocess-aspect`.
     *
     * 2 = sentimen per-aspek dihitung dari klausa (bukan kalimat penuh) dan
     *     penjajaran indeks dipertahankan untuk baris kosong.
     */
    public const PIPELINE_VERSION = 2;

    public $timeout = 1800; // 30 menit untuk data besar

    public $tries = 3;

    public $backoff = [120, 300, 600]; // 2 menit, 5 menit, 10 menit

    /**
     * Kunci unik agar satu analisis tidak pernah diproses dua kali serentak.
     *
     * Pertahanan berlapis di atas `retry_after` pada config/queue.php. Nilai
     * lama retry_after (90 detik) lebih kecil daripada $timeout, sehingga
     * worker melepaskan job yang MASIH BERJALAN kembali ke antrean dan worker
     * kedua mengambilnya - analisis yang sama berjalan berkali-kali sekaligus.
     * Terukur, analisis nyata memakan 95-235 detik, jadi praktis semuanya
     * terduplikasi.
     *
     * retry_after sudah diperbaiki, tetapi kunci ini membuat kelas kesalahan
     * itu tidak bisa terulang lewat jalan lain: dispatch ganda dari controller,
     * pengguna menekan tombol dua kali, atau worker tambahan yang dijalankan
     * bersamaan.
     */
    public $uniqueFor = 2100;

    protected $analysis;

    public function __construct(TextAnalysis $analysis)
    {
        $this->analysis = $analysis;
    }

    /**
     * Satu kunci per analisis; analisis berbeda tetap boleh berjalan paralel.
     */
    public function uniqueId(): string
    {
        return 'analysis-'.$this->analysis->id;
    }

    public function handle(NLPApiService $nlpService): void
    {
        try {
            Log::info("Processing analysis ID: {$this->analysis->id}");

            // ✅ Update status to processing (10%)
            $this->updateProgress(10, 'Memulai analisis...');

            // Panaskan bobot model sebelum analisis. Service NLP memuat bobot
            // secara malas demi RAM, sehingga permintaan pertama tiap jenis
            // membayar biaya unduh dan muat model - di kontainer baru bisa
            // memakan menit, dan dari sini tak bisa dibedakan dari analisis
            // yang menggantung. Kegagalannya tidak fatal: analisis tetap jalan,
            // hanya lebih lambat.
            $this->updateProgress(12, 'Menyiapkan model...');
            $nlpService->warmUp();
            $this->analysis->update([
                'status' => 'processing',
                'started_at' => now(),
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
            $progressCallback = function ($progress, $message) {
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
                    $numTopics = $this->getNumTopics();

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

                    // Formulir menampilkan pilihan mode aspek untuk tipe
                    // 'combined' juga, jadi keduanya harus ikut diteruskan -
                    // sebelumnya dibuang di sini dan analisis gabungan selalu
                    // memakai mode automatic.
                    $predefinedAspects = $this->getPredefinedAspects();
                    $mode = $this->getAspectMode($predefinedAspects);

                    $result = $nlpService->analyzeCombined(
                        $texts,
                        $preprocessingConfig,
                        $progressCallback,
                        $predefinedAspects,
                        $mode,
                        $this->getNumTopics()
                    );
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
                'completed_at' => now(),
            ]);

            $duration = $this->analysis->started_at->diffInSeconds(now());

            AnalysisLog::createLog(
                'completed',
                $this->analysis->user_id,
                $this->analysis->id,
                'Analysis completed successfully',
                [
                    'duration' => $duration,
                    'text_count' => $textCount,
                ]
            );

            Log::info("Analysis ID {$this->analysis->id} completed successfully in {$duration} seconds");

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // ✅ Connection timeout - will retry
            Log::warning("Analysis ID {$this->analysis->id} connection timeout: ".$e->getMessage());

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
            Log::error("Analysis ID {$this->analysis->id} request error: ".$e->getMessage());

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
    private function handleFailure(Exception $e, ?string $customMessage = null): void
    {
        $errorMessage = $customMessage ?? $e->getMessage();

        Log::error("Analysis ID {$this->analysis->id} failed: ".$errorMessage);
        Log::error('Exception: '.get_class($e));
        Log::error('Stack trace: '.$e->getTraceAsString());

        $this->analysis->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);

        AnalysisLog::createLog(
            'failed',
            $this->analysis->user_id,
            $this->analysis->id,
            'Analysis failed',
            [
                'error' => $errorMessage,
                'exception' => get_class($e),
                'attempts' => $this->attempts(),
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
        $configId = $this->analysis->metadata['preprocessing_config_id'] ?? null;
        $resolver = app(PreprocessingConfigResolver::class);
        $resolved = $resolver->resolve($configId ? (int) $configId : null);

        Log::info("Preprocessing config untuk analisis {$this->analysis->id}", [
            'config_id' => $configId,
            'config_name' => $resolver->resolveName($configId ? (int) $configId : null),
            'custom_stopwords' => count($resolved['custom_stopwords'] ?? []),
        ]);

        return $resolved;
    }

    /**
     * Jumlah topik pilihan pengguna. 0 berarti otomatis (API mencari sendiri),
     * dan itu nilai yang sah - jangan diperlakukan sebagai "kosong".
     */
    protected function getNumTopics(): int
    {
        $numTopics = $this->analysis->metadata['num_topics'] ?? 5;

        return is_numeric($numTopics) ? (int) $numTopics : 5;
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
            'summary' => null,
        ];

        $originalTexts = $this->analysis->raw_data;

        switch ($this->analysis->analysis_type) {
            case 'sentiment':
                $data['predictions'] = $this->attachOriginalTexts(
                    $analysisResults['predictions'] ?? [],
                    $originalTexts
                );
                $data['sentiment_distribution'] = $analysisResults['distribution'] ?? null;
                $data['metrics'] = $this->withReviewQueue(
                    $analysisResults['metrics'] ?? null,
                    $analysisResults['review_queue'] ?? null
                );
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
                $data['metrics'] = $this->withReviewQueue(
                    $analysisResults['sentiment']['metrics'] ?? null,
                    $analysisResults['sentiment']['review_queue'] ?? null
                );
                $data['summary'] = $this->generateCombinedSummary($analysisResults);
                break;
        }

        // Penanda versi dipakai analysis:reprocess-aspect untuk membedakan
        // hasil lama dari hasil terbaru. Menebaknya dari isi data tidak andal:
        // analisis yang benar pun bisa tidak punya kelas netral sama sekali.
        $metrics = $data['metrics'] ?? [];
        if (! is_array($metrics)) {
            $metrics = [];
        }
        $metrics['pipeline_version'] = self::PIPELINE_VERSION;
        $data['metrics'] = $metrics;

        AnalysisResult::updateOrCreate(
            ['text_analysis_id' => $this->analysis->id],
            $data
        );
    }

    /**
     * Simpan antrean tinjauan bersama metrics.
     *
     * review_queue adalah saudara metrics pada respons API, tapi disimpan di
     * dalam metrics agar tidak perlu kolom baru - keduanya sama-sama penanda
     * mutu hasil, dan halaman hasil membacanya dari satu tempat.
     */
    protected function withReviewQueue(?array $metrics, ?array $reviewQueue): ?array
    {
        if (empty($reviewQueue)) {
            return $metrics;
        }

        $metrics ??= [];
        $metrics['review_queue'] = $reviewQueue;

        return $metrics;
    }

    /**
     * Kembalikan teks asli (sebelum preprocessing) ke setiap prediksi.
     *
     * Pemetaan memakai original_index yang dikirim NLPApiService saat batching,
     * supaya posisi tetap benar walaupun ada batch yang gagal di tengah.
     *
     * Sejak service Python mengembalikan `processed_text` sendiri, nilai itu
     * TIDAK boleh ditimpa di sini. Dulu `text` dari API berisi teks hasil
     * pembersihan sehingga pemindahan ini benar; sekarang `text` sudah berisi
     * teks asli, dan menimpanya membuat processed_text == text sehingga panel
     * "teks setelah preprocessing" pada halaman hasil hilang diam-diam.
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

                if (! isset($prediction['processed_text'])) {
                    $prediction['processed_text'] = $prediction['text'] ?? null;
                }

                $prediction['text'] = $originalTexts[$index];
            }

            if (! empty($documentAspects[$index])) {
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
        Log::error("Job permanently failed for analysis ID {$this->analysis->id}: ".$exception->getMessage());

        $this->analysis->update([
            'status' => 'failed',
            'error_message' => 'Analisis gagal setelah beberapa percobaan. '.$exception->getMessage(),
            'completed_at' => now(),
        ]);

        AnalysisLog::createLog(
            'failed',
            $this->analysis->user_id,
            $this->analysis->id,
            'Job permanently failed after all retries',
            [
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]
        );
    }
}
