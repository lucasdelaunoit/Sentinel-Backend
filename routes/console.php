<?php

use App\Jobs\RecalculateProjectRiskJob;
use App\Jobs\RefreshRuleViolationsJob;
use App\Models\Project;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RefreshRuleViolationsJob)->dailyAt('02:00');

// Daily recalc of all active projects — refreshes cache columns + writes snapshot rows.
// Catches days when no mutation triggered a recalc, keeps trend history continuous.
Schedule::call(function () {
    Project::active()->each(fn(Project $project) => RecalculateProjectRiskJob::dispatch($project));
})->dailyAt('03:00')->name('recalculate-project-metrics');
