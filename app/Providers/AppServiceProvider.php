<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\NLPApiService;
use App\Services\FileProcessingService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NLPApiService::class, function ($app) {
            return new NLPApiService();
        });

        $this->app->singleton(FileProcessingService::class, function ($app) {
            return new FileProcessingService();
        });
    }

    public function boot(): void
    {
        //
    }
}