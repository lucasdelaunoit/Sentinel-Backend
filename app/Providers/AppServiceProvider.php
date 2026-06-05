<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so working_days + holidays load once per request and are reused
        // across every row of an absence collection (hybrid normalized-day counts).
        $this->app->singleton(\App\Services\AbsenceNormalizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
