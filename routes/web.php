<?php

use App\Http\Controllers\Admin\EvaluationController;
use App\Http\Controllers\Admin\TrainingController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AnalysisFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\YouTubeScraperController;
use App\Services\AnalysisInterpretationService;
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
        Route::post('/preprocess-preview', [AnalysisController::class, 'previewPreprocessing'])
            ->name('preprocess-preview')
            ->middleware('throttle:20,1');
        Route::get('/nlp-status', [AnalysisController::class, 'nlpStatus'])
            ->name('nlp-status')
            ->middleware('throttle:30,1');
        Route::post('/warm-up', [AnalysisController::class, 'warmUpModels'])
            ->name('warm-up')
            ->middleware('throttle:5,1');
        Route::get('/{id}', [AnalysisController::class, 'show'])->whereNumber('id')->name('show');

        // Daftar prediksi dipaginasi di server; lihat PredictionQueryService.
        Route::get('/{id}/predictions', [AnalysisController::class, 'predictions'])
            ->whereNumber('id')
            ->name('predictions')
            ->middleware('throttle:60,1');

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

        // Rincian hasil per kolom pemisah. Murni membaca ulang data tersimpan,
        // jadi throttle-nya sama longgarnya dengan daftar prediksi.
        Route::get('/{id}/segments', [AnalysisController::class, 'segments'])
            ->name('segments')
            ->whereNumber('id')
            ->middleware('throttle:60,1');

        // Interpretasi AI per bagian hasil. Throttle-nya ketat karena tiap
        // permintaan memanggil Gemini dan memotong kuota harian.
        Route::post('/{id}/interpret/{section}', [AnalysisController::class, 'interpret'])
            ->name('interpret')
            ->whereNumber('id')
            ->whereIn('section', AnalysisInterpretationService::SECTIONS)
            ->middleware('throttle:10,1');

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
        Route::post('/scrape-by-url', [YouTubeScraperController::class, 'scrapeByUrl'])->name('scrape-by-url');
        Route::get('/api-status', [YouTubeScraperController::class, 'checkApiStatus'])->name('api-status');
        Route::post('/video-info', [YouTubeScraperController::class, 'getVideoInfo'])->name('video-info');
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
    // whereNumber wajib: tanpa itu {id} juga menangkap '/training/export-csv'
    // dan '/training/preview-retrain' karena keduanya terdaftar setelah ini,
    // sehingga tombol Export CSV selalu berakhir 404.
    Route::get('/training/{id}', [TrainingController::class, 'show'])->whereNumber('id')->name('training.show');

    // 3. API Data Load (AJAX untuk DataTable di Show)
    Route::get('/training/{id}/data', [TrainingController::class, 'getData'])->whereNumber('id')->name('training.data');

    // 4. Action: Update Single Item
    Route::post('/training/update/{itemId}', [TrainingController::class, 'update'])->name('training.update');

    // 6. Action: Stopwords Management
    Route::post('/training/stopword', [TrainingController::class, 'storeStopword'])->name('training.stopword');
    Route::delete('/training/stopword/{id}', [TrainingController::class, 'destroyStopword'])->name('training.stopword.delete');

    // 7. Action: Export & Trigger
    Route::get('/training/export-csv', [TrainingController::class, 'export'])->name('training.export');
    // Periksa komposisi data latih sebelum melatih apa pun
    Route::get('/training/preview-retrain', [TrainingController::class, 'previewTraining'])->name('training.preview');
    Route::post('/training/trigger-retrain', [TrainingController::class, 'triggerTraining'])->name('training.trigger'); // Pastikan ini POST

    Route::post('/training/sync-all', [TrainingController::class, 'syncAll'])->name('training.sync');

    // 8. Evaluasi hold-out (golden dataset)
    // whereNumber pada tiap {id} dengan alasan yang sama seperti di atas.
    Route::get('/evaluation', [EvaluationController::class, 'index'])->name('evaluation.index');
    Route::post('/evaluation', [EvaluationController::class, 'store'])->name('evaluation.store');
    // Terdaftar sebelum /evaluation/{id} - kalau tidak, {id} menelannya.
    Route::get('/evaluation/sampling', [EvaluationController::class, 'sampling'])->name('evaluation.sampling');
    Route::post('/evaluation/sampling', [EvaluationController::class, 'storeSample'])->name('evaluation.sample');
    Route::get('/evaluation/{id}', [EvaluationController::class, 'show'])->whereNumber('id')->name('evaluation.show');
    Route::post('/evaluation/{id}/freeze', [EvaluationController::class, 'freeze'])->whereNumber('id')->name('evaluation.freeze');
    Route::post('/evaluation/{id}/unfreeze', [EvaluationController::class, 'unfreeze'])->whereNumber('id')->name('evaluation.unfreeze');
    Route::post('/evaluation/{id}/run', [EvaluationController::class, 'run'])->whereNumber('id')->name('evaluation.run');
    Route::delete('/evaluation/{id}', [EvaluationController::class, 'destroy'])->whereNumber('id')->name('evaluation.destroy');
    Route::get('/evaluation/{id}/annotation', [EvaluationController::class, 'downloadAnnotation'])->whereNumber('id')->name('evaluation.annotation');
    Route::post('/evaluation/{id}/annotation', [EvaluationController::class, 'uploadAnnotation'])->whereNumber('id')->name('evaluation.annotation.upload');
    Route::post('/evaluation/{id}/drop-unlabeled', [EvaluationController::class, 'dropUnlabeled'])->whereNumber('id')->name('evaluation.drop-unlabeled');
    Route::get('/evaluation/run/{runId}/errors', [EvaluationController::class, 'exportErrors'])->whereNumber('runId')->name('evaluation.errors');
});

require __DIR__.'/auth.php';
