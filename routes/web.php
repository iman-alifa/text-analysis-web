<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AnalysisFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\TrainingController;
use App\Http\Controllers\YouTubeScraperController;

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
        
        // Delete
        Route::delete('/{id}', [AnalysisController::class, 'destroy'])->name('destroy');

        // Feedback / Active Learning (user's own analyses only)
        Route::get('/{id}/feedback', [AnalysisFeedbackController::class, 'create'])->name('feedback');
        Route::post('/{id}/feedback', [AnalysisFeedbackController::class, 'store'])->name('feedback.store');
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
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    
    // 1. Dashboard Training (Index)
    Route::get('/training', [TrainingController::class, 'index'])->name('training.index');
    
    // 2. Workspace Koreksi (Show per File)
    Route::get('/training/{id}', [TrainingController::class, 'show'])->name('training.show');
    
    // 3. API Data Load (AJAX untuk DataTable di Show)
    Route::get('/training/{id}/data', [TrainingController::class, 'getData'])->name('training.data');
    
    // 4. Action: Update Single Item
    Route::post('/training/update/{itemId}', [TrainingController::class, 'update'])->name('training.update');
    
    // 5. Action: Bulk Update
    Route::post('/training/bulk-update', [TrainingController::class, 'bulkCorrect'])->name('training.bulk');
    
    // 6. Action: Stopwords Management
    Route::post('/training/stopword', [TrainingController::class, 'storeStopword'])->name('training.stopword');
    Route::delete('/training/stopword/{id}', [TrainingController::class, 'destroyStopword'])->name('training.stopword.delete');
    
    // 7. Action: Export & Trigger
    Route::get('/training/export-csv', [TrainingController::class, 'export'])->name('training.export');
    Route::post('/training/trigger-retrain', [TrainingController::class, 'triggerTraining'])->name('training.trigger'); // Pastikan ini POST

    Route::post('/training/sync-all', [TrainingController::class, 'syncAll'])->name('training.sync');
});

require __DIR__.'/auth.php';