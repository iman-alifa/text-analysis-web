<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\TrainingController;
use App\Http\Controllers\YouTubeScraperController;
use App\Http\Controllers\AnalysisFeedbackController;

// Landing Page
Route::get('/', [LandingController::class, 'index'])->name('landing');

// Dashboard (Protected - Auth Required)
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Analysis Routes
    Route::prefix('analysis')->name('analysis.')->group(function () {
        Route::get('/', [AnalysisController::class, 'index'])->name('index');
        Route::get('/create', [AnalysisController::class, 'create'])->name('create');
        Route::post('/store', [AnalysisController::class, 'store'])->name('store');
        Route::post('/upload-file', [AnalysisController::class, 'uploadFile'])->name('upload-file');
        Route::post('/process', [AnalysisController::class, 'process'])->name('process');
        Route::get('/{id}', [AnalysisController::class, 'show'])->name('show');
        
        Route::get('/{id}/poll-status', [AnalysisController::class, 'pollStatus'])
             ->name('poll-status')
             ->middleware('throttle:30,1'); // 30 requests per minute (every 2-3 seconds)
        
        Route::get('/{id}/can-poll', [AnalysisController::class, 'canPoll'])
             ->name('can-poll')
             ->middleware('throttle:20,1'); // 20 requests per minute
        
        Route::get('/{id}/status', [AnalysisController::class, 'checkStatus'])->name('status');
        
        // Export Routes
        Route::get('/{id}/export-pdf', [AnalysisController::class, 'exportPdf'])->name('export-pdf');
        Route::get('/{id}/export-csv', [AnalysisController::class, 'exportCsv'])->name('export-csv');

        // Feedback / Active Learning
        Route::get('/{id}/feedback', [AnalysisFeedbackController::class, 'show'])->name('feedback');
        
        // Delete
        Route::delete('/{id}', [AnalysisController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('youtube')->name('youtube.')->group(function () {
        Route::get('/scraper', [YouTubeScraperController::class, 'index'])->name('scraper');
        Route::post('/search', [YouTubeScraperController::class, 'search'])->name('search');
        Route::post('/scrape-comments', [YouTubeScraperController::class, 'scrapeComments'])->name('scrape-comments');
        Route::get('/download/{filename}', [YouTubeScraperController::class, 'download'])->name('download');
    });
    
    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Training Routes (accessible to all authenticated users)
    Route::prefix('training')->name('training.')->group(function () {
        // Export must be listed before /{id} to prevent route conflict
        Route::get('/export-csv', [TrainingController::class, 'export'])->name('export');

        // 1. Dashboard Training (Index)
        Route::get('/', [TrainingController::class, 'index'])->name('index');

        // 2. Workspace Koreksi (Show per File)
        Route::get('/{id}', [TrainingController::class, 'show'])->name('show');

        // 3. API Data Load (AJAX untuk DataTable di Show)
        Route::get('/{id}/data', [TrainingController::class, 'getData'])->name('data');

        // 4. Action: Update Single Item
        Route::post('/update/{itemId}', [TrainingController::class, 'update'])->name('update');

        // 5. Action: Bulk Update
        Route::post('/bulk-update', [TrainingController::class, 'bulkCorrect'])->name('bulk');

        // 6. Action: Stopwords Management (admin only)
        Route::post('/stopword', [TrainingController::class, 'storeStopword'])->name('stopword');
        Route::delete('/stopword/{id}', [TrainingController::class, 'destroyStopword'])->name('stopword.delete');

        // 7. Action: Trigger & Sync (admin only)
        Route::post('/trigger-retrain', [TrainingController::class, 'triggerTraining'])->name('trigger');
        Route::post('/sync-all', [TrainingController::class, 'syncAll'])->name('sync');
    });

    // Analysis Feedback route is now inside the analysis prefix group above

});

require __DIR__.'/auth.php';