<?php

namespace App\Providers;

use App\Models\TextAnalysis;
use App\Policies\AnalysisPolicy;
use App\Services\FileProcessingService;
use App\Services\NLPApiService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NLPApiService::class, function ($app) {
            return new NLPApiService;
        });

        $this->app->singleton(FileProcessingService::class, function ($app) {
            return new FileProcessingService;
        });
    }

    public function boot(): void
    {
        Gate::policy(TextAnalysis::class, AnalysisPolicy::class);
    }
}
