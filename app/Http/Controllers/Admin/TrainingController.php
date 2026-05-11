<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\CustomStopword;
use App\Services\TrainingItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrainingController extends Controller
{
    public function __construct(private TrainingItemService $trainingItemService) {}
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

        return view('admin.training.index', compact('stats', 'batches', 'stopwords'));
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
        $evaluation = $this->buildEvaluationSummary($analysis, $correctedItems);
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
    
    public function triggerTraining() {
        return back()->with('success', 'Request training dikirim.');
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

    private function buildEvaluationSummary(TextAnalysis $analysis, Collection $correctedItems): array
    {
        return [
            'sentiment' => $this->buildSentimentEvaluation($analysis->analysis_type, $correctedItems),
            'aspect' => $this->buildAspectEvaluation($analysis->analysis_type, $correctedItems),
            'topic' => $this->buildTopicEvaluation($analysis),
            'corrected_total' => $correctedItems->count(),
        ];
    }

    private function buildSentimentEvaluation(string $analysisType, Collection $correctedItems): ?array
    {
        if (!in_array($analysisType, ['sentiment', 'combined'])) {
            return null;
        }

        $labels = ['positive', 'neutral', 'negative'];
        $confusion = [];
        foreach ($labels as $actual) {
            foreach ($labels as $predicted) {
                $confusion[$actual][$predicted] = 0;
            }
        }

        $compared = 0;
        $correct = 0;

        foreach ($correctedItems as $item) {
            $predicted = strtolower((string) ($item->predicted_sentiment ?? ''));
            $actual = strtolower((string) ($item->corrected_sentiment ?? ''));

            if (!in_array($predicted, $labels) || !in_array($actual, $labels)) {
                continue;
            }

            $confusion[$actual][$predicted]++;
            $compared++;

            if ($actual === $predicted) {
                $correct++;
            }
        }

        if ($compared === 0) {
            return [
                'available' => false,
                'message' => 'Belum ada data koreksi sentimen untuk evaluasi.',
            ];
        }

        $perClass = [];
        $precisionTotal = 0;
        $recallTotal = 0;
        $f1Total = 0;

        foreach ($labels as $label) {
            $tp = $confusion[$label][$label];
            $fp = 0;
            $fn = 0;

            foreach ($labels as $other) {
                if ($other !== $label) {
                    $fp += $confusion[$other][$label];
                    $fn += $confusion[$label][$other];
                }
            }

            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
            $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0;

            $perClass[$label] = [
                'precision' => round($precision * 100, 1),
                'recall' => round($recall * 100, 1),
                'f1' => round($f1 * 100, 1),
                'support' => array_sum($confusion[$label]),
            ];

            $precisionTotal += $precision;
            $recallTotal += $recall;
            $f1Total += $f1;
        }

        return [
            'available' => true,
            'accuracy' => round(($correct / $compared) * 100, 1),
            'macro_precision' => round(($precisionTotal / count($labels)) * 100, 1),
            'macro_recall' => round(($recallTotal / count($labels)) * 100, 1),
            'macro_f1' => round(($f1Total / count($labels)) * 100, 1),
            'evaluated_rows' => $compared,
            'per_class' => $perClass,
            'confusion_matrix' => $confusion,
            'labels' => $labels,
        ];
    }

    private function buildAspectEvaluation(string $analysisType, Collection $correctedItems): ?array
    {
        if (!in_array($analysisType, ['aspect', 'combined'])) {
            return null;
        }

        $tp = 0;
        $fp = 0;
        $fn = 0;
        $exactMatches = 0;
        $compared = 0;

        foreach ($correctedItems as $item) {
            if (is_null($item->corrected_aspects)) {
                continue;
            }

            $predicted = $this->normalizeAspects($item->detected_aspects ?? []);
            $actual = $this->normalizeAspects($item->corrected_aspects ?? []);

            $predSet = array_fill_keys($predicted, true);
            $actualSet = array_fill_keys($actual, true);

            $intersectCount = count(array_intersect_key($predSet, $actualSet));
            $tp += $intersectCount;
            $fp += count(array_diff_key($predSet, $actualSet));
            $fn += count(array_diff_key($actualSet, $predSet));

            if ($predicted === $actual) {
                $exactMatches++;
            }

            $compared++;
        }

        if ($compared === 0) {
            return [
                'available' => false,
                'message' => 'Belum ada data koreksi aspek untuk evaluasi.',
            ];
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
        $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0;

        return [
            'available' => true,
            'exact_match' => round(($exactMatches / $compared) * 100, 1),
            'precision' => round($precision * 100, 1),
            'recall' => round($recall * 100, 1),
            'f1' => round($f1 * 100, 1),
            'evaluated_rows' => $compared,
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
        ];
    }

    private function buildTopicEvaluation(TextAnalysis $analysis): ?array
    {
        if (!in_array($analysis->analysis_type, ['topic', 'combined'])) {
            return null;
        }

        $topicResults = $analysis->result->topic_results ?? [];
        if (is_string($topicResults)) {
            $topicResults = json_decode($topicResults, true) ?? [];
        }

        $topics = $topicResults['topics'] ?? [];
        $wordFrequencies = $topicResults['word_frequencies'] ?? [];

        if (empty($topics)) {
            return [
                'available' => false,
                'message' => 'Data topik belum tersedia untuk evaluasi.',
            ];
        }

        $proportions = collect($topics)
            ->pluck('proportion')
            ->map(fn ($p) => max(0, (float) $p))
            ->filter(fn ($p) => $p > 0)
            ->values();

        $dominantShare = $proportions->isNotEmpty() ? round($proportions->max() * 100, 1) : 0;
        $avgShare = $proportions->isNotEmpty() ? round(($proportions->sum() / $proportions->count()) * 100, 1) : 0;

        // Shannon entropy untuk mengukur seberapa seimbang distribusi proporsi topik.
        // Semakin tinggi entropy, semakin merata distribusi topik di dokumen.
        $entropy = 0.0;
        foreach ($proportions as $p) {
            if ($p < 1e-10) {
                continue;
            }
            $entropy += -($p * log($p, M_E));
        }
        $maxEntropy = $proportions->count() > 1 ? log($proportions->count(), M_E) : 0;
        $balanceScore = $maxEntropy > 0 ? round(($entropy / $maxEntropy) * 100, 1) : 0;

        $uniqueTopicWords = collect($topics)
            ->pluck('words')
            ->flatten()
            ->filter()
            ->unique()
            ->count();

        return [
            'available' => true,
            'topic_count' => count($topics),
            'dominant_topic_share' => $dominantShare,
            'average_topic_share' => $avgShare,
            'distribution_balance' => $balanceScore,
            'unique_topic_words' => $uniqueTopicWords,
            'word_frequency_terms' => is_array($wordFrequencies) ? count($wordFrequencies) : 0,
        ];
    }

    private function normalizeAspects(array|string|null $aspects): array
    {
        if (is_string($aspects)) {
            $decoded = json_decode($aspects, true);
            // Fallback untuk data lama/non-JSON yang disimpan sebagai string CSV "a,b,c".
            $aspects = is_array($decoded) ? $decoded : explode(',', $aspects);
        }

        if (!is_array($aspects)) {
            return [];
        }

        $normalized = array_map(
            fn ($aspect) => strtolower(trim((string) $aspect)),
            $aspects
        );

        $normalized = array_values(array_filter($normalized, fn ($aspect) => $aspect !== ''));
        sort($normalized);

        return array_values(array_unique($normalized));
    }
}
