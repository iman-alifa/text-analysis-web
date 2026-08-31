<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\CustomStopword;
use App\Models\ModelTraining;
use App\Models\EvaluationSnapshot;
use App\Jobs\RetrainModel;
use App\Services\NLPApiService;
use App\Services\ModelEvaluationService;
use App\Services\TrainingItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingController extends Controller
{
    public function __construct(
        private TrainingItemService $trainingItemService,
        private ModelEvaluationService $modelEvaluationService
    ) {}
    /**
     * HALAMAN 1: INDEX (DASHBOARD)
     */
    public function index()
    {
        // 1. Hitung Statistik Global dari TrainingItem
        $totalItems = TrainingItem::count();
        $verifiedItems = TrainingItem::where('is_corrected', true)->count();
        
        // Hitung akurasi (Bandingkan prediksi AI vs Koreksi Admin)
        $accurateItems = TrainingItem::where('is_corrected', true)
            ->whereColumn('predicted_sentiment', 'corrected_sentiment') 
            ->count();

        $stats = [
            'accuracy' => $verifiedItems > 0 ? round(($accurateItems / $verifiedItems) * 100, 1) : 0,
            'total_texts' => $totalItems,
            'corrected_count' => $verifiedItems,
            'pending_count' => $totalItems - $verifiedItems,
        ];

        // 2. Ambil Daftar File (Batch)
        $batches = TextAnalysis::withCount(['trainingItems as verified_count' => function($q){
                $q->where('is_corrected', true);
            }])
            ->latest()
            ->paginate(10);

        // 3. Stopwords
        $stopwords = CustomStopword::latest()->get();

        // 4. Riwayat retraining model (loop active learning)
        $trainings = ModelTraining::with('user')->latest()->limit(10)->get();

        // 5. Riwayat metrik evaluasi antar-iterasi
        $snapshots = EvaluationSnapshot::latest()->limit(20)->get();

        return view('admin.training.index', compact('stats', 'batches', 'stopwords', 'trainings', 'snapshots'));
    }

    /**
     * HALAMAN 2: SHOW (WORKSPACE KOREKSI)
     */
    public function show($id)
    {
        $analysis = TextAnalysis::with('result')->findOrFail($id);
        
        // LOGIC "LAZY LOAD":
        // Jika tabel training_items kosong untuk file ini, ekstrak dari JSON sekarang.
        if ($analysis->trainingItems()->count() === 0) {
             $this->trainingItemService->extractJsonToTable($analysis);
        }
        
        // Hitung statistik file + evaluasi model per dokumen
        $allItems = $analysis->trainingItems()->get();
        $correctedItems = $allItems->where('is_corrected', true)->values();

        $total = $allItems->count();
        $corrected = $correctedItems->count();
        $evaluation = $this->modelEvaluationService->buildEvaluationSummary($analysis, $correctedItems, $allItems);
        $accuracy = $evaluation['sentiment']['accuracy'] ?? 0;

        return view('admin.training.show', compact('analysis', 'accuracy', 'total', 'corrected', 'evaluation'));
    }

    /**
     * API: LOAD DATA TABLE (AJAX)
     */
    public function getData(Request $request, $id) 
    {
        // Pastikan ID valid
        $exists = TextAnalysis::where('id', $id)->exists();
        if (!$exists) {
            return response()->json(['error' => 'Analysis not found'], 404);
        }

        $query = TrainingItem::where('text_analysis_id', $id);

        // Filter
        if ($request->status === 'pending') $query->where('is_corrected', false);
        if ($request->status === 'corrected') $query->where('is_corrected', true);
        if ($request->search) $query->where('text_content', 'like', '%'.$request->search.'%');
        if ($request->sentiment) $query->where('predicted_sentiment', $request->sentiment);

        $data = $query->latest()->paginate(20);

        // Mapping Data
        $formatted = $data->getCollection()->map(function($item) {
            return [
                'id' => $item->id,
                // Gunakan utf8_encode jika perlu, atau pastikan string aman
                'text_content' => mb_convert_encoding($item->text_content, 'UTF-8', 'UTF-8'), 
                'predicted_sentiment' => $item->predicted_sentiment ?? 'neutral',
                'confidence_score' => (float) $item->confidence_score,
                
                'corrected_sentiment' => $item->corrected_sentiment,
                'corrected_aspects' => $item->corrected_aspects,
                'correction_notes' => $item->correction_notes,
                
                'detected_aspects' => $item->detected_aspects ?? [],
                
                'is_corrected' => (bool) $item->is_corrected,
                'verified_at' => $item->verified_at ? $item->verified_at->format('d M Y, H:i') : null,
            ];
        });

        return response()->json([
            'data' => $formatted,
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
            'total' => $data->total(),
        ]);
    }

    /**
     * ACTION: UPDATE ITEM (SINGLE)
     */
    public function update(Request $request, $id)
    {
        $item = TrainingItem::findOrFail($id);
        
        $item->update([
            'corrected_sentiment' => $request->corrected_sentiment,
            'corrected_aspects' => $request->corrected_aspects,
            'correction_notes' => $request->correction_notes,
            'is_corrected' => true,
            'verified_at' => now(),
            'verified_by' => auth()->id(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * ACTION: BULK UPDATE
     */
    public function bulkCorrect(Request $request)
    {
        $request->validate(['text_ids' => 'required|array', 'corrected_sentiment' => 'required']);

        TrainingItem::whereIn('id', $request->text_ids)->update([
            'corrected_sentiment' => $request->corrected_sentiment,
            'is_corrected' => true,
            'verified_at' => now(),
            'verified_by' => auth()->id(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * ACTION: EXPORT CSV
     */
    public function export()
    {
        $data = TrainingItem::with('textAnalysis:id,title')
            ->where('is_corrected', true)
            ->get();

        $filename = 'training_dataset_' . date('Y-m-d') . '.csv';
        
        $headers = [
            "Content-type" => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma" => "no-cache",
            "Cache-Control" => "must-revalidate, post-check=0, pre-check=0",
            "Expires" => "0"
        ];

        $callback = function() use ($data) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['text', 'label', 'aspects', 'source_file']);

            foreach ($data as $row) {
                fputcsv($file, [
                    $row->text_content,
                    $row->corrected_sentiment,
                    json_encode($row->corrected_aspects ?? []),
                    $row->textAnalysis->title ?? 'Unknown'
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
    
    // --- TOPIC STOPWORDS ---
    public function storeStopword(Request $request) {
        CustomStopword::create(['word' => $request->word, 'added_by' => auth()->id()]);
        return back()->with('success', 'Stopword ditambahkan');
    }

    public function destroyStopword($id) {
        CustomStopword::destroy($id);
        return back()->with('success', 'Stopword dihapus');
    }
    
    /**
     * Kirim seluruh data yang sudah dikoreksi ke endpoint retraining NLP API.
     *
     * Sebelumnya method ini hanya menampilkan flash message tanpa melakukan
     * apa pun, sehingga loop active learning tidak pernah benar-benar tertutup.
     */
    public function triggerTraining(Request $request)
    {
        $validated = $request->validate([
            'model_type' => 'nullable|in:sentiment,aspect,both',
            'epochs' => 'nullable|integer|min:1|max:20',
        ]);

        $modelType = $validated['model_type'] ?? 'both';
        $epochs = (int) ($validated['epochs'] ?? 3);

        if (ModelTraining::running()->exists()) {
            return back()->with('error', 'Masih ada proses training yang berjalan. Tunggu sampai selesai.');
        }

        $items = TrainingItem::where('is_corrected', true)->get();

        if ($items->isEmpty()) {
            return back()->with('error', 'Belum ada data terkoreksi untuk dilatih.');
        }

        $payloads = [
            'sentiment' => $this->buildSentimentTrainingData($items),
            'aspect' => $this->buildAspectTrainingData($items),
        ];

        $dispatched = [];
        $skipped = [];
        $trainings = [];

        foreach ($payloads as $type => $data) {
            if ($modelType !== 'both' && $modelType !== $type) {
                continue;
            }

            if (count($data) < NLPApiService::MIN_RETRAIN_SAMPLES) {
                $skipped[] = sprintf(
                    '%s (%d dari minimal %d sampel)',
                    $type,
                    count($data),
                    NLPApiService::MIN_RETRAIN_SAMPLES
                );
                continue;
            }

            $training = ModelTraining::create([
                'model_type' => $type,
                'status' => 'pending',
                'total_samples' => count($data),
                'epochs' => $epochs,
                'learning_rate' => 0.00002,
                'triggered_by' => auth()->id(),
            ]);

            RetrainModel::dispatch($training, $data);
            $trainings[] = $training;
            $dispatched[] = sprintf('%s (%d sampel)', $type, count($data));
        }

        if (empty($dispatched)) {
            return back()->with('error', 'Data belum cukup untuk training: ' . implode(', ', $skipped));
        }

        // Potret metrik sebelum model dilatih ulang. Snapshot berikutnya (saat
        // retraining dipicu lagi) menjadi pembanding untuk melihat perbaikan.
        $this->modelEvaluationService->captureSnapshot(
            $trainings[0] ?? null,
            'Sebelum retraining: ' . implode(', ', $dispatched)
        );

        $message = 'Training dikirim ke NLP API: ' . implode(', ', $dispatched)
                 . '. Pantau statusnya di tabel Riwayat Training.';

        if (!empty($skipped)) {
            $message .= ' Dilewati: ' . implode(', ', $skipped) . '.';
        }

        return back()->with('success', $message);
    }

    /**
     * Format sesuai SentimentRetrainRequest di NLP API: [{text, label}].
     */
    private function buildSentimentTrainingData($items): array
    {
        $validLabels = ['positive', 'neutral', 'negative'];

        return $items
            ->filter(fn ($item) => in_array(strtolower((string) $item->corrected_sentiment), $validLabels, true))
            ->filter(fn ($item) => filled($item->text_content))
            ->map(fn ($item) => [
                'text' => $item->text_content,
                'label' => strtolower($item->corrected_sentiment),
            ])
            ->values()
            ->all();
    }

    /**
     * Format sesuai AspectRetrainRequest di NLP API: [{text, aspects: []}].
     */
    private function buildAspectTrainingData($items): array
    {
        return $items
            ->filter(fn ($item) => !empty($item->corrected_aspects) && filled($item->text_content))
            ->map(fn ($item) => [
                'text' => $item->text_content,
                'aspects' => array_values(array_filter(array_map(
                    fn ($aspect) => trim((string) $aspect),
                    (array) $item->corrected_aspects
                ))),
            ])
            ->filter(fn ($row) => !empty($row['aspects']))
            ->values()
            ->all();
    }

    /**
     * ACTION: SINKRONISASI SEMUA DATA (MASS SYNC)
     */
    public function syncAll()
    {
        $analyses = TextAnalysis::with('result')
            ->where('status', 'completed')
            ->get();
            
        $count = 0;
        foreach ($analyses as $analysis) {
            if ($analysis->trainingItems()->exists()) continue;
            
            $this->trainingItemService->extractJsonToTable($analysis);
            $count++;
        }

        return back()->with('success', "Berhasil sinkronisasi {$count} file.");
    }
}
