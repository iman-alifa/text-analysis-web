<?php

namespace App\Http\Controllers;

use App\Models\TextAnalysis;
use App\Models\Dataset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        
        // Statistics
        $stats = [
            'total_analyses' => TextAnalysis::where('user_id', $user->id)->count(),
            'completed_analyses' => TextAnalysis::where('user_id', $user->id)
                                                ->where('status', 'completed')
                                                ->count(),
            'processing_analyses' => TextAnalysis::where('user_id', $user->id)
                                                 ->where('status', 'processing')
                                                 ->count(),
            'failed_analyses' => TextAnalysis::where('user_id', $user->id)
                                             ->where('status', 'failed')
                                             ->count(),
            'total_datasets' => Dataset::where('user_id', $user->id)->count(),
            'avg_accuracy' => $this->calculateAverageAccuracy($user->id),
            'avg_processing_time' => $this->calculateAverageProcessingTime($user->id),
        ];
        
        // Recent Analyses
        $recentAnalyses = TextAnalysis::where('user_id', $user->id)
                                      ->with('result')
                                      ->orderBy('created_at', 'desc')
                                      ->limit(10)
                                      ->get();
        
        // Chart Data - Analysis by Type
        $analysisByType = TextAnalysis::where('user_id', $user->id)
                                      ->selectRaw('analysis_type, count(*) as count')
                                      ->groupBy('analysis_type')
                                      ->get();
        
        // Chart Data - Analysis by Status
        $analysisByStatus = TextAnalysis::where('user_id', $user->id)
                                        ->selectRaw('status, count(*) as count')
                                        ->groupBy('status')
                                        ->get();
        
        // Chart Data - Last 7 Days Activity
        $last7Days = TextAnalysis::where('user_id', $user->id)
                                 ->where('created_at', '>=', now()->subDays(7))
                                 ->selectRaw('DATE(created_at) as date, count(*) as count')
                                 ->groupBy('date')
                                 ->orderBy('date', 'asc')
                                 ->get();
        
        return view('dashboard.index', compact(
            'stats',
            'recentAnalyses',
            'analysisByType',
            'analysisByStatus',
            'last7Days'
        ));
    }
    
    private function calculateAverageAccuracy($userId)
    {
        $analyses = TextAnalysis::where('user_id', $userId)
                                ->where('status', 'completed')
                                ->with('result')
                                ->get();
        
        if ($analyses->isEmpty()) {
            return 0;
        }
        
        $totalAccuracy = 0;
        $count = 0;
        
        foreach ($analyses as $analysis) {
            if ($analysis->result && isset($analysis->result->metrics['accuracy'])) {
                $totalAccuracy += $analysis->result->metrics['accuracy'];
                $count++;
            }
        }
        
        return $count > 0 ? round($totalAccuracy / $count, 2) : 0;
    }
    
    private function calculateAverageProcessingTime($userId)
    {
        $analyses = TextAnalysis::where('user_id', $userId)
                                ->where('status', 'completed')
                                ->whereNotNull('started_at')
                                ->whereNotNull('completed_at')
                                ->get();
        
        if ($analyses->isEmpty()) {
            return '0s';
        }
        
        $totalSeconds = 0;
        
        foreach ($analyses as $analysis) {
            $totalSeconds += $analysis->started_at->diffInSeconds($analysis->completed_at);
        }
        
        $avgSeconds = $totalSeconds / $analyses->count();
        
        if ($avgSeconds < 60) {
            return round($avgSeconds, 1) . 's';
        } elseif ($avgSeconds < 3600) {
            return round($avgSeconds / 60, 1) . 'm';
        } else {
            return round($avgSeconds / 3600, 1) . 'h';
        }
    }
}