<?php

namespace App\Http\Controllers;

use App\Models\TextAnalysis;
use App\Models\AnalysisLog;
use App\Models\PreprocessingConfig;
use App\Services\FileProcessingService;
use App\Services\NLPApiService;
use App\Services\PreprocessingConfigResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;

class AnalysisController extends Controller
{
    protected $fileProcessingService;

    public function __construct(FileProcessingService $fileProcessingService)
    {
        $this->fileProcessingService = $fileProcessingService;
    }

    public function index()
    {
        $analyses = TextAnalysis::where('user_id', Auth::id())
                                ->with('result')
                                ->orderBy('created_at', 'desc')
                                ->paginate(15);
        
        return view('analysis.index', compact('analyses'));
    }
    
    public function create()
    {
        $preprocessingConfigs = PreprocessingConfig::all();
        
        return view('analysis.create', compact('preprocessingConfigs'));
    }

    /**
     * Kesiapan model NLP (AJAX).
     *
     * Bobot model dimuat malas, jadi permintaan pertama tiap jenis membayar
     * biaya muat model. Dari sisi UI itu tak bisa dibedakan dari analisis yang
     * menggantung, sehingga kesiapannya perlu terlihat sebelum memulai.
     */
    public function nlpStatus(NLPApiService $nlpService)
    {
        $health = $nlpService->health();

        if ($health === null) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan analisis tidak merespons. Pastikan NLP API berjalan di ' . config('services.nlp_api.url') . '.',
            ], 503);
        }

        $weights = $health['weights_loaded'] ?? [];

        return response()->json([
            'success' => true,
            'weights_loaded' => $weights,
            'all_ready' => !empty($weights) && collect($weights)->every(fn ($loaded) => $loaded === true),
            'model' => [
                'sentiment_source' => $health['sentiment_source'] ?? null,
                'sentiment_base' => $health['sentiment_base'] ?? null,
                'sentiment_temperature' => $health['sentiment_temperature'] ?? null,
                'sentiment_review_threshold' => $health['sentiment_review_threshold'] ?? null,
            ],
        ]);
    }

    /**
     * Muat bobot model lebih dulu supaya analisis pertama tidak terasa macet.
     */
    public function warmUpModels(NLPApiService $nlpService)
    {
        $ready = $nlpService->warmUp();

        return response()->json([
            'success' => true,
            'all_ready' => $ready,
            'message' => $ready
                ? 'Semua model siap.'
                : 'Sebagian model belum siap. Analisis tetap bisa dijalankan, hanya lebih lambat di awal.',
        ]);
    }

    /**
     * Pratinjau preprocessing (AJAX).
     *
     * Mengirim `task` sesuai jenis analisis yang sedang dipilih, karena tiap
     * modul di NLP API memaksakan kebijakannya sendiri di atas konfigurasi
     * pengguna. Tanpa itu pratinjau menampilkan teks ter-stem padahal analisis
     * sentimen justru mematikan stemming.
     */
    public function previewPreprocessing(
        Request $request,
        NLPApiService $nlpService,
        PreprocessingConfigResolver $resolver
    ) {
        $validator = Validator::make($request->all(), [
            'texts' => 'required|array|min:1|max:5',
            'texts.*' => 'required|string|max:10000',
            'analysis_type' => 'required|in:sentiment,aspect,topic,combined',
            'preprocessing_config_id' => 'nullable|exists:preprocessing_configs,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $configId = $request->filled('preprocessing_config_id')
            ? (int) $request->input('preprocessing_config_id')
            : null;

        $task = $resolver->taskForAnalysisType($request->input('analysis_type'));

        try {
            $response = $nlpService->preprocessText(
                $request->input('texts'),
                $resolver->resolve($configId),
                $task
            );

            return response()->json([
                'success' => true,
                'task' => $task,
                'config_name' => $resolver->resolveName($configId),
                'original' => $request->input('texts'),
                'preprocessed' => $response['preprocessed'] ?? [],
                'applied_policy' => $response['applied_policy'] ?? null,
                'notice' => $this->preprocessingNotice($task),
            ]);

        } catch (\Exception $e) {
            \Log::warning('Pratinjau preprocessing gagal: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghubungi layanan analisis. Pastikan NLP API berjalan.',
            ], 503);
        }
    }

    /**
     * Penjelasan mengapa hasil pratinjau bisa berbeda dari pilihan pengguna.
     */
    private function preprocessingNotice(?string $task): ?string
    {
        return match ($task) {
            'transformer' => 'Stemming dan penghapusan stopword dinonaktifkan untuk analisis sentimen karena merusak deteksi negasi.',
            'bag_of_words' => 'Stemming dan penghapusan stopword diaktifkan untuk pemodelan topik karena model bag-of-words diuntungkan pembersihan agresif.',
            'span' => 'Teks dibiarkan utuh untuk ekstraksi aspek karena aspek ditemukan berdasarkan posisi karakter pada teks asli.',
            default => 'Analisis gabungan menjalankan tiga modul, masing-masing dengan kebijakan preprocessing sendiri. Pratinjau ini memakai konfigurasi apa adanya.',
        };
    }

    /**
     * Upload file for preview (AJAX endpoint)
     */
    public function uploadFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $processedData = $this->fileProcessingService->processFile($file);
            
            return response()->json([
                'success' => true,
                'data' => $processedData,
                'message' => 'File berhasil diproses'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store analysis
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'input_type' => 'required|in:manual,file',
            'analysis_type' => 'required|in:sentiment,aspect,topic,combined',
            'preprocessing_config_id' => 'nullable|exists:preprocessing_configs,id',
            
            // Manual input
            'manual_text' => 'required_if:input_type,manual|nullable|string',
            
            // File upload
            'file' => 'required_if:input_type,file|nullable|file|mimes:csv,txt,xlsx,xls|max:10240',
            
            // File configuration - Excel/CSV
            'file_has_header' => 'nullable|string',
            'text_column_name' => 'nullable|string',
            'text_column_index' => 'nullable|integer|min:1',
            'csv_delimiter' => 'nullable|string',
            'excel_sheet' => 'nullable|integer|min:0',
            
            // File configuration - TXT
            'txt_separator' => 'nullable|in:newline,period,double_newline,custom',
            'txt_custom_separator' => 'nullable|string',
            'txt_encoding' => 'nullable|string',
            
            // Aspect analysis
            'aspect_mode' => 'nullable|in:automatic,rule-based',
            'predefined_aspects' => 'nullable|string',
            
            // Topic analysis
            // 0 berarti otomatis (API mencari sendiri jumlah topik terbaik).
            // 1 ditolak API, jadi ditolak lebih dulu di sini agar pesannya ramah.
            'num_topics' => 'nullable|integer|in:0,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                        ->withErrors($validator)
                        ->withInput();
        }

        try {
            $data = [
                'user_id' => Auth::id(),
                'title' => $request->title,
                'description' => $request->description,
                'analysis_type' => $request->analysis_type,
                'status' => 'pending',
            ];

            // Prepare metadata
            $metadata = [];

            if ($request->preprocessing_config_id) {
                $metadata['preprocessing_config_id'] = (int) $request->preprocessing_config_id;
            }

            // filled(), bukan truthy: num_topics = 0 (mode otomatis) itu sah
            // tetapi bernilai falsy, sehingga pengecekan lama membuangnya.
            if ($request->filled('num_topics')) {
                $metadata['num_topics'] = (int) $request->input('num_topics');
            }
            
            if ($request->aspect_mode) {
                $metadata['aspect_mode'] = $request->aspect_mode;
            }
            
            if ($request->predefined_aspects) {
                $aspects = array_map('trim', explode(',', $request->predefined_aspects));
                $metadata['predefined_aspects'] = $aspects;
            }

            // Process input based on type
            if ($request->input_type === 'manual') {
                // Manual text input
                $texts = $this->processManualInput($request->manual_text);
                $data['input_type'] = 'manual';
                $data['raw_data'] = $texts;
                $data['total_records'] = count($texts);
                
            } else {
                // File upload
                $file = $request->file('file');
                $fileExtension = strtolower($file->getClientOriginalExtension());
                
                // Save file
                $fileData = $this->fileProcessingService->saveFile($file, 'uploads');
                
                // Prepare file configuration
                $fileConfig = $this->prepareFileConfig($request, $fileExtension);
                
                // Process file with configuration
                $processedData = $this->fileProcessingService->processFileWithConfig($file, $fileConfig);
                
                // Store file configuration in metadata
                $metadata['file_config'] = $fileConfig;
                
                // Set input_type sesuai extension file
                $data['input_type'] = $fileExtension;
                $data['file_path'] = $fileData['path'];
                $data['file_name'] = $fileData['filename'];
                $data['raw_data'] = $processedData['texts'];
                $data['total_records'] = $processedData['total'];
            }

            // Batas API diperiksa di sini supaya pesannya jelas dan analisis
            // tidak terlanjur dibuat lalu gagal di worker dengan HTTP 422.
            if ($pesanBatas = $this->cekBatasApi($data['raw_data'])) {
                return redirect()->back()
                            ->withErrors(['manual_text' => $pesanBatas])
                            ->withInput();
            }

            // Add metadata if not empty
            // PENTING: jangan json_encode di sini. Kolom metadata sudah di-cast
            // 'array' di model, jadi encoding manual membuat data ter-encode dua kali
            // dan seluruh isinya (num_topics, predefined_aspects) jadi tidak terbaca.
            if (!empty($metadata)) {
                $data['metadata'] = $metadata;
            }

            // Create analysis
            $analysis = TextAnalysis::create($data);

            // Log creation
            AnalysisLog::createLog(
                'created',
                Auth::id(),
                $analysis->id,
                'Analysis created and queued for processing',
                [
                    'input_type' => $data['input_type'],
                    'total_records' => $data['total_records'],
                    'analysis_type' => $request->analysis_type,
                    'file_config' => $metadata['file_config'] ?? null,
                ]
            );

            // Dispatch job for processing
            \App\Jobs\ProcessTextAnalysis::dispatch($analysis);

            return redirect()->route('analysis.show', $analysis->id)
                        ->with('success', 'Analisis berhasil dibuat dan sedang diproses!');

        } catch (\Exception $e) {
            \Log::error('Analysis Store Error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return redirect()->back()
                        ->with('error', 'Terjadi kesalahan: ' . $e->getMessage())
                        ->withInput();
        }
    }

    /**
     * Periksa batas yang ditegakkan service NLP sebelum analisis dibuat.
     *
     * API menolak dengan HTTP 422 bila dilanggar; memeriksanya di sini membuat
     * pesannya bisa dimengerti dan menyebut baris keberapa yang bermasalah.
     *
     * @return string|null Pesan galat, atau null bila lolos.
     */
    private function cekBatasApi(array $texts): ?string
    {
        $maxTexts = (int) config('services.nlp_api.max_texts', 10000);
        $maxLength = (int) config('services.nlp_api.max_text_length', 10000);

        if (count($texts) > $maxTexts) {
            return sprintf(
                'Jumlah teks (%s) melebihi batas layanan analisis (%s). Pecah datanya menjadi beberapa analisis.',
                number_format(count($texts)),
                number_format($maxTexts)
            );
        }

        foreach ($texts as $index => $text) {
            if (mb_strlen((string) $text) > $maxLength) {
                return sprintf(
                    'Teks baris ke-%d terlalu panjang (%s karakter, batas %s).',
                    $index + 1,
                    number_format(mb_strlen((string) $text)),
                    number_format($maxLength)
                );
            }
        }

        return null;
    }

    /**
     * Prepare file configuration from request
     */
    private function prepareFileConfig(Request $request, $fileExtension)
    {
        $config = [];
        
        // Excel/CSV configuration
        if (in_array($fileExtension, ['xlsx', 'xls', 'csv'])) {
            $config['file_has_header'] = $request->input('file_has_header', 'on');
            
            if ($config['file_has_header'] == 'on') {
                $config['text_column_name'] = $request->input('text_column_name');
            } else {
                $config['text_column_index'] = $request->input('text_column_index', 1);
            }
            
            // CSV specific
            if ($fileExtension === 'csv') {
                $config['csv_delimiter'] = $request->input('csv_delimiter', ',');
            }
            
            // Excel specific
            if (in_array($fileExtension, ['xlsx', 'xls'])) {
                $config['excel_sheet'] = $request->input('excel_sheet', 0);
            }
        }
        
        // TXT configuration
        if ($fileExtension === 'txt') {
            $config['txt_separator'] = $request->input('txt_separator', 'newline');
            
            if ($config['txt_separator'] === 'custom') {
                $config['txt_custom_separator'] = $request->input('txt_custom_separator', "\n");
            }
            
            $config['txt_encoding'] = $request->input('txt_encoding', 'utf-8');
        }
        
        return $config;
    }

    /**
     * Process manual text input
     */
    private function processManualInput($text)
    {
        // Split by new lines
        $lines = explode("\n", $text);
        
        // Clean and filter
        $texts = array_filter(array_map(function($line) {
            return trim($line);
        }, $lines), function($line) {
            return !empty($line);
        });

        return array_values($texts);
    }

    /**
     * Show analysis details
     */
    public function show($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->with('result')
                                ->findOrFail($id);
        
        // Prepare chart data if analysis is completed
        $chartData = null;
        if ($analysis->status === 'completed' && $analysis->result) {
            $chartData = $this->prepareChartData($analysis);
        }
        
        return view('analysis.show', compact('analysis', 'chartData'));
    }

    /**
     * Prepare chart data for visualization
     */
    private function prepareChartData($analysis)
    {
        $result = $analysis->result;
        $data = [];

        // Sentiment chart data
        if ($result->sentiment_distribution) {
            $data['sentiment'] = \App\Helpers\ChartHelper::prepareSentimentChartData(
                $result->sentiment_distribution
            );
        }

        // Aspect chart data
        $aspects = $result->normalizedAspectResults();

        if (!empty($aspects)) {
            $data['aspect'] = \App\Helpers\ChartHelper::prepareAspectChartData($aspects);
        }

        $data['aspects'] = $aspects;

        // Asosiasi aspek-topik (PMI). Null berarti belum ada data asli,
        // dan view harus menampilkan keadaan kosong.
        $data['association'] = \App\Helpers\ChartHelper::prepareAssociationData(
            $result->association_results,
            $result->document_aspects ?? [],
            $result->topic_results
        );

        // Topic chart data
        if ($result->topic_results && isset($result->topic_results['topics'])) {
            $data['topic'] = \App\Helpers\ChartHelper::prepareTopicChartData(
                $result->topic_results['topics']
            );
            
            // Word cloud data
            if (isset($result->topic_results['word_frequencies'])) {
                $data['wordCloud'] = \App\Helpers\ChartHelper::prepareWordCloudData(
                    $result->topic_results['word_frequencies']
                );
            }
        }

        return $data;
    }
    
    /**
     * Export ringkasan hasil analisis ke PDF.
     */
    public function exportPdf($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->with('result')
                                ->findOrFail($id);

        if ($analysis->status !== 'completed' || !$analysis->result) {
            return redirect()->route('analysis.show', $analysis->id)
                             ->with('error', 'Analisis belum selesai, hasil belum bisa diekspor.');
        }

        $pdf = Pdf::loadView('analysis.export-pdf', [
            'analysis' => $analysis,
            'result' => $analysis->result,
        ])->setPaper('a4');

        return $pdf->download($this->exportFilename($analysis, 'pdf'));
    }

    /**
     * Export hasil analisis per teks ke CSV.
     */
    public function exportCsv($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->with('result')
                                ->findOrFail($id);

        if ($analysis->status !== 'completed' || !$analysis->result) {
            return redirect()->route('analysis.show', $analysis->id)
                             ->with('error', 'Analisis belum selesai, hasil belum bisa diekspor.');
        }

        $filename = $this->exportFilename($analysis, 'csv');
        $predictions = $analysis->result->predictions ?? [];

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($predictions) {
            $handle = fopen('php://output', 'w');

            // BOM supaya Excel membaca karakter Indonesia dengan benar
            fwrite($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, ['no', 'teks', 'teks_preprocessed', 'sentimen', 'confidence', 'aspek']);

            foreach ($predictions as $index => $prediction) {
                $sentiment = $prediction['sentiment'] ?? null;

                if (is_array($sentiment)) {
                    $sentiment = $sentiment['label'] ?? null;
                }

                fputcsv($handle, [
                    $index + 1,
                    $prediction['original_text'] ?? $prediction['text'] ?? '',
                    $prediction['processed_text'] ?? '',
                    $sentiment ?? '',
                    $prediction['confidence'] ?? '',
                    implode(', ', (array) ($prediction['aspects'] ?? [])),
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function exportFilename(TextAnalysis $analysis, string $extension): string
    {
        $slug = Str::slug($analysis->title) ?: 'analisis';

        return "{$slug}-{$analysis->id}-" . now()->format('Ymd-His') . ".{$extension}";
    }

    /**
     * Delete analysis
     */
    public function destroy($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())->findOrFail($id);
        
        // Delete file if exists
        // Disk 'public' mengikuti FileProcessingService::saveFile(), yang
        // menyimpan upload lewat storeAs(..., 'public').
        if ($analysis->file_path && Storage::disk('public')->exists($analysis->file_path)) {
            Storage::disk('public')->delete($analysis->file_path);
        }
        
        $analysis->delete();
        
        AnalysisLog::createLog(
            'deleted',
            Auth::id(),
            null,
            'Analysis deleted: ' . $analysis->title
        );
        
        return redirect()->route('analysis.index')
                        ->with('success', 'Analysis deleted successfully');
    }

    /**
     * Check analysis status
     */
    public function checkStatus($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->findOrFail($id);
        
        return response()->json([
            'status' => $analysis->status,
            'started_at' => $analysis->started_at?->toISOString(),
            'completed_at' => $analysis->completed_at?->toISOString(),
            'error_message' => $analysis->error_message,
            'duration' => $analysis->duration
        ]);
    }

    /**
     * Poll status (for real-time updates)
     */
    public function pollStatus($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->findOrFail($id);
        
        // Update last polled timestamp
        $analysis->update(['last_polled_at' => now()]);
        
        return response()->json($analysis->getStatusForPolling());
    }

    /**
     * Check if analysis can be polled
     */
    public function canPoll($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->findOrFail($id);
        
        // Only allow polling if still processing
        if (!$analysis->isProcessing()) {
            return response()->json([
                'can_poll' => false,
                'reason' => 'Analysis is not in processing state',
                'current_status' => $analysis->status
            ]);
        }
        
        return response()->json([
            'can_poll' => true,
            'status' => $analysis->getStatusForPolling()
        ]);
    }

    /**
     * Generate topic interpretation via LLM
     */
    public function generateTopicInterpretation($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())
                                ->findOrFail($id);
        
        $result = $analysis->result;
        
        if (!$result || empty($result->topic_results['topics'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hasil topic modeling tidak ditemukan'
            ], 404);
        }

        // Jika interpretasi sudah ada, langsung kembalikan
        if (isset($result->topic_results['interpretation']) && !empty($result->topic_results['interpretation'])) {
            return response()->json([
                'success' => true,
                'data' => $result->topic_results['interpretation'],
                'message' => 'Interpretasi sudah ada'
            ]);
        }

        $llmService = new \App\Services\LlmService();
        $interpretations = $llmService->generateTopicInterpretations($result->topic_results['topics']);

        if (empty($interpretations)) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghasilkan interpretasi dari AI. Cek konfigurasi API Key atau coba lagi nanti.'
            ], 500);
        }

        // Simpan interpretasi ke dalam JSON topic_results
        $topicResults = $result->topic_results;
        $topicResults['interpretation'] = $interpretations;
        
        $result->topic_results = $topicResults;
        $result->save();

        return response()->json([
            'success' => true,
            'data' => $interpretations,
            'message' => 'Interpretasi berhasil dibuat'
        ]);
    }
}