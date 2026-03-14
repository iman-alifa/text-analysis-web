<?php

namespace App\Http\Controllers;

use App\Models\TextAnalysis;
use App\Http\Controllers\Admin\TrainingController;
use Illuminate\Http\Request;

class AnalysisFeedbackController extends Controller
{
    /**
     * Handle the Active Learning feedback request for an analysis.
     * Ensures training items are extracted (lazy sync), then redirects to the training workspace.
     */
    public function show($id)
    {
        $user = auth()->user();
        $analysis = TextAnalysis::with('result')->findOrFail($id);

        // Authorization: only the owner (or admin) can provide feedback
        if (!$user->isAdmin() && $analysis->user_id !== $user->id) {
            abort(403, 'Anda tidak memiliki akses ke analisis ini.');
        }

        // Only completed analyses can receive feedback
        if ($analysis->status !== 'completed') {
            return redirect()->route('analysis.show', $id)
                ->with('error', 'Feedback hanya dapat diberikan untuk analisis yang telah selesai.');
        }

        // Ensure training items are synced (lazy extraction)
        if ($analysis->trainingItems()->count() === 0 && $analysis->result) {
            app(TrainingController::class)->extractJsonToTable($analysis);
        }

        // Redirect to the training workspace which provides the full correction interface
        return redirect()->route('training.show', $id);
    }
}
