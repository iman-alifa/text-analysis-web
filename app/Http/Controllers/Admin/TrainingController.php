<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Models\CustomStopword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingController extends Controller
{
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
             $this->extractJsonToTable($analysis);
        }
        
        // Hitung statistik file
        $total = $analysis->trainingItems()->count();
        $corrected = $analysis->trainingItems()->where('is_corrected', true)->count();
        
        $accuracy = 0;
        if($corrected > 0) {
            $correctMatch = $analysis->trainingItems()
                ->where('is_corrected', true)
                ->whereColumn('predicted_sentiment', 'corrected_sentiment')
                ->count();
            $accuracy = round(($correctMatch / $corrected) * 100, 1);
        }

        return view('admin.training.show', compact('analysis', 'accuracy', 'total', 'corrected'));
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
            
            $this->extractJsonToTable($analysis);
            $count++;
        }

        return back()->with('success', "Berhasil sinkronisasi {$count} file.");
    }

    /**
     * PRIVATE HELPER: BONGKAR JSON KE TABLE
     * Dilengkapi logika pemetaan aspek global ke baris.
     */
    private function extractJsonToTable(TextAnalysis $analysis)
    {
        if (!$analysis->result) return;

        // 1. SIAPKAN KAMUS ASPEK (Dari data global aspect_results)
        // Struktur aspect_results: [{"aspect": "hasil", ...}, {"aspect": "harga", ...}]
        $globalAspectsRaw = $analysis->result->aspect_results ?? [];
        if (is_string($globalAspectsRaw)) {
            $globalAspectsRaw = json_decode($globalAspectsRaw, true);
        }

        $aspectKeywords = [];
        if (is_array($globalAspectsRaw)) {
            foreach ($globalAspectsRaw as $item) {
                if (isset($item['aspect'])) {
                    $aspectKeywords[] = strtolower($item['aspect']);
                }
            }
        }

        // 2. SIAPKAN DATA BARIS (Dari kolom predictions)
        // Struktur predictions: [{"text": "...", "sentiment": "...", ...}]
        $sourceData = $analysis->result->predictions 
                   ?? $analysis->result->result 
                   ?? []; 

        $rows = [];
        if (is_string($sourceData)) {
            $rows = json_decode($sourceData, true);
        } elseif (is_array($sourceData)) {
            $rows = $sourceData;
        }

        // Handle wrapper "results" jika ada
        if (isset($rows['results']) && is_array($rows['results'])) {
            $rows = $rows['results'];
        }

        if (!is_array($rows) || empty($rows)) return;

        $batch = [];
        $now = now();

        foreach ($rows as $row) {
            if (empty($row['text'])) continue;

            // 3. Normalisasi Sentimen & Confidence
            $sentimentLabel = 'neutral';
            if (isset($row['sentiment'])) {
                $sentimentLabel = is_array($row['sentiment']) 
                    ? ($row['sentiment']['label'] ?? 'neutral') 
                    : $row['sentiment'];
            }
            $confidence = $row['confidence'] ?? $row['score'] ?? 0;

            // 4. LOGIKA PENTING: MAPPING ASPEK
            // Jika baris tidak punya aspek, cari dari kamus global
            $rowAspects = $row['aspects'] ?? [];

            if (empty($rowAspects) && !empty($aspectKeywords)) {
                $textLower = strtolower($row['text']);
                foreach ($aspectKeywords as $keyword) {
                    // Regex \b untuk mencocokkan kata utuh (misal: "app" tidak match "apple")
                    if (preg_match("/\b" . preg_quote($keyword, '/') . "\b/i", $textLower)) {
                        $rowAspects[] = $keyword;
                    }
                }
            }

            $batch[] = [
                'text_analysis_id' => $analysis->id,
                'text_content' => $row['text'],
                'predicted_sentiment' => strtolower($sentimentLabel),
                'confidence_score' => (float) $confidence,
                'detected_aspects' => json_encode(array_values(array_unique($rowAspects))), // Hapus duplikat
                'is_corrected' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 200) {
                TrainingItem::insert($batch);
                $batch = [];
            }
        }
        
        if (!empty($batch)) {
            TrainingItem::insert($batch);
        }
    }
}