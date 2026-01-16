<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AnalysisController;
use Illuminate\Support\Facades\Route;

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
    });
    
    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';