<?php

namespace App\Http\Controllers;

use App\Models\TextAnalysis;
use App\Models\AnalysisLog;
use App\Models\PreprocessingConfig;
use App\Services\FileProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

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

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'title' => 'required|string|max:255',
    //         'description' => 'nullable|string|max:1000',
    //         'input_type' => 'required|in:manual,file',
    //         'analysis_type' => 'required|in:sentiment,aspect,topic,combined',
    //         'preprocessing_config_id' => 'nullable|exists:preprocessing_configs,id',
            
    //         // For manual input
    //         'manual_text' => 'required_if:input_type,manual|nullable|string',
            
    //         // For file upload
    //         'file' => 'required_if:input_type,file|nullable|file|mimes:csv,txt,xlsx,xls|max:10240',
    //         'text_column' => 'nullable|string',
            
    //         // For aspect analysis
    //         'aspect_mode' => 'nullable|in:automatic,rule-based',
    //         'predefined_aspects' => 'nullable|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return redirect()->back()
    //                     ->withErrors($validator)
    //                     ->withInput();
    //     }

    //     try {
    //         $data = [
    //             'user_id' => Auth::id(),
    //             'title' => $request->title,
    //             'description' => $request->description,
    //             'analysis_type' => $request->analysis_type,
    //             'status' => 'pending',
    //         ];

    //         // Process input based on type
    //         if ($request->input_type === 'manual') {
    //             // Manual text input
    //             $texts = $this->processManualInput($request->manual_text);
    //             $data['input_type'] = 'manual';
    //             $data['raw_data'] = json_encode($texts);
    //             $data['total_records'] = count($texts);
                
    //         } else {
    //             // File upload
    //             $file = $request->file('file');
    //             $fileExtension = strtolower($file->getClientOriginalExtension());
                
    //             // Simpan file
    //             $fileData = $this->fileProcessingService->saveFile($file, 'uploads');
                
    //             // Proses file
    //             $processedData = $this->fileProcessingService->processFile($file);
                
    //             // Set input_type sesuai extension file
    //             $data['input_type'] = $fileExtension; // csv, txt, xlsx, atau xls
    //             $data['file_path'] = $fileData['path'];
    //             $data['file_name'] = $fileData['filename'];
    //             $data['raw_data'] = json_encode($processedData['texts']);
    //             $data['total_records'] = $processedData['total'];
    //         }

    //         // Create analysis
    //         $analysis = TextAnalysis::create($data);

    //         // Log
    //         \App\Models\AnalysisLog::createLog(
    //             'created',
    //             Auth::id(),
    //             $analysis->id,
    //             'Analysis created and queued for processing',
    //             [
    //                 'input_type' => $data['input_type'],
    //                 'total_records' => $data['total_records'],
    //                 'analysis_type' => $request->analysis_type,
    //             ]
    //         );

    //         return redirect()->route('analysis.show', $analysis->id)
    //                     ->with('success', 'Analisis berhasil dibuat dan sedang diproses!');

    //     } catch (\Exception $e) {
    //         \Log::error('Analysis Store Error: ' . $e->getMessage());
            
    //         return redirect()->back()
    //                     ->with('error', 'Terjadi kesalahan: ' . $e->getMessage())
    //                     ->withInput();
    //     }
    // }

    
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'input_type' => 'required|in:manual,file',
            'analysis_type' => 'required|in:sentiment,aspect,topic,combined',
            'preprocessing_config_id' => 'nullable|exists:preprocessing_configs,id',
            
            // For manual input
            'manual_text' => 'required_if:input_type,manual|nullable|string',
            
            // For file upload
            'file' => 'required_if:input_type,file|nullable|file|mimes:csv,txt,xlsx,xls|max:10240',
            'text_column' => 'nullable|string',
            
            // For aspect analysis
            'aspect_mode' => 'nullable|in:automatic,rule-based',
            'predefined_aspects' => 'nullable|string',
            
            // For topic analysis
            'num_topics' => 'nullable|integer|min:2|max:20',
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
            
            if ($request->num_topics) {
                $metadata['num_topics'] = $request->num_topics;
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
                
                // Simpan file
                $fileData = $this->fileProcessingService->saveFile($file, 'uploads');
                
                // Proses file
                $processedData = $this->fileProcessingService->processFile($file);
                
                // Set input_type sesuai extension file
                $data['input_type'] = $fileExtension;
                $data['file_path'] = $fileData['path'];
                $data['file_name'] = $fileData['filename'];
                $data['raw_data'] = $processedData['texts'];
                $data['total_records'] = $processedData['total'];
            }

            // Add metadata if not empty
            if (!empty($metadata)) {
                $data['metadata'] = json_encode($metadata);
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
                ]
            );

            // Dispatch job for processing
            \App\Jobs\ProcessTextAnalysis::dispatch($analysis);

            return redirect()->route('analysis.show', $analysis->id)
                        ->with('success', 'Analisis berhasil dibuat dan sedang diproses!');

        } catch (\Exception $e) {
            \Log::error('Analysis Store Error: ' . $e->getMessage());
            
            return redirect()->back()
                        ->with('error', 'Terjadi kesalahan: ' . $e->getMessage())
                        ->withInput();
        }
    }

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
    
    // public function show($id)
    // {
    //     $analysis = TextAnalysis::where('user_id', Auth::id())
    //                             ->with('result')
    //                             ->findOrFail($id);
        
    //     return view('analysis.show', compact('analysis'));
    // }

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
        if ($result->aspect_results) {
            $data['aspect'] = \App\Helpers\ChartHelper::prepareAspectChartData(
                $result->aspect_results
            );
        }

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
    
    public function destroy($id)
    {
        $analysis = TextAnalysis::where('user_id', Auth::id())->findOrFail($id);
        
        // Delete file if exists
        if ($analysis->file_path && Storage::disk('public')->exists($analysis->file_path)) {
            Storage::disk('public')->delete($analysis->file_path);
        }
        
        $analysis->delete();
        
        \App\Models\AnalysisLog::createLog(
            'deleted',
            Auth::id(),
            null,
            'Analysis deleted: ' . $analysis->title
        );
        
        return redirect()->route('analysis.index')
                        ->with('success', 'Analysis deleted successfully');
    }

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

    
    // private function processManualInput($text)
    // {
    //     // Split by new lines
    //     $lines = explode("\n", $text);
        
    //     // Clean and filter
    //     $texts = array_filter(array_map(function($line) {
    //         return trim($line);
    //     }, $lines), function($line) {
    //         return !empty($line);
    //     });

    //     return array_values($texts);
    // }
}