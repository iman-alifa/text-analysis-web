<?php

namespace App\Http\Controllers;

use App\Models\TextAnalysis;
use App\Models\TrainingItem;
use App\Services\ModelEvaluationService;
use App\Services\TrainingItemService;
use Illuminate\Http\Request;

class AnalysisFeedbackController extends Controller
{
    /** Jumlah baris koreksi per halaman. */
    private const PER_PAGE = 25;

    public function __construct(
        private TrainingItemService $trainingItemService,
        private ModelEvaluationService $modelEvaluationService
    ) {}

    /**
     * Show the feedback form for the given analysis.
     * Only the owner of the analysis may access this page.
     */
    public function create(int $id)
    {
        $analysis = TextAnalysis::with('result')->findOrFail($id);

        $this->authorize('view', $analysis);

        // Ensure training items have been extracted from the result JSON.
        if ($analysis->trainingItems()->count() === 0) {
            $this->trainingItemService->extractJsonToTable($analysis);
        }

        // Evaluasi memerlukan seluruh baris, tetapi yang dirender hanya satu
        // halaman: menampilkan ratusan kartu koreksi sekaligus membuat halaman
        // ini mencapai 2,1 MB dan berat dibuka saat mengoreksi.
        $allItems = $analysis->trainingItems()->get();
        $correctedItems = $allItems->where('is_corrected', true)->values();
        $evaluation = $this->modelEvaluationService->buildEvaluationSummary($analysis, $correctedItems, $allItems);

        // Baris paling tidak yakin didahulukan supaya yang paling perlu
        // ditinjau langsung terlihat.
        $items = $analysis->trainingItems()
            ->orderBy('confidence_score', 'asc')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Count how many corrections this user has already made (across all analyses).
        $userCorrectionCount = TrainingItem::where('verified_by', auth()->id())
            ->where('is_corrected', true)
            ->count();

        return view('analysis.feedback', compact('analysis', 'items', 'userCorrectionCount', 'evaluation'));
    }

    /**
     * Save the user's corrections for one or more training items.
     */
    public function store(Request $request, int $id)
    {
        $analysis = TextAnalysis::findOrFail($id);

        $this->authorize('view', $analysis);

        $request->validate([
            'corrections' => 'required|array|min:1',
            'corrections.*.item_id' => 'required|integer|exists:training_items,id',
            'corrections.*.corrected_sentiment' => 'nullable|in:positive,negative,neutral',
            'corrections.*.corrected_aspects' => 'nullable|string',
            'corrections.*.correction_notes' => 'nullable|string|max:500',
        ]);

        $userId = auth()->id();
        $savedCount = 0;

        foreach ($request->corrections as $correction) {
            $item = TrainingItem::where('id', $correction['item_id'])
                ->where('text_analysis_id', $analysis->id)
                ->first();

            if (! $item) {
                continue;
            }

            // Parse aspects from comma-separated string
            $aspects = null;
            if (! empty($correction['corrected_aspects'])) {
                $aspects = array_values(array_filter(
                    array_map('trim', explode(',', $correction['corrected_aspects']))
                ));
            }

            $item->update([
                'corrected_sentiment' => $correction['corrected_sentiment'] ?? $item->predicted_sentiment,
                'corrected_aspects' => $aspects,
                'correction_notes' => $correction['correction_notes'] ?? null,
                'is_corrected' => true,
                'verified_at' => now(),
                'verified_by' => $userId,
            ]);

            $savedCount++;
        }

        // Kembali ke halaman koreksi yang sama, bukan ke halaman hasil: dengan
        // daftar yang dipaginasi, pengguna biasanya melanjutkan ke halaman
        // berikutnya alih-alih berhenti setelah satu batch koreksi.
        return redirect()
            ->route('analysis.feedback', [
                'id' => $analysis->id,
                'page' => $request->integer('page') ?: null,
            ])
            ->with('feedback_success', "Terima kasih! {$savedCount} koreksi Anda telah disimpan dan akan membantu meningkatkan akurasi model.");
    }
}
