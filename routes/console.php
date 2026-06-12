<?php

use App\Managers\CalculationRunManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly full metric cascade (projects → users → org aggregates) as one tracked
// run with live progress. Catches days when no mutation triggered a recalc,
// keeps trend history continuous.
Schedule::call(fn() => app(CalculationRunManager::class)->queueFullRecalculation())
    ->dailyAt('03:00')
    ->name('full-metric-recalculation');
