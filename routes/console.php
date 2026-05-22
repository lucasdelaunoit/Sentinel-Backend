<?php

use App\Jobs\CaptureProjectSnapshotsJob;
use App\Jobs\RefreshRuleViolationsJob;
use App\Models\Project;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new RefreshRuleViolationsJob)->dailyAt('02:00');

Schedule::call(function () {
    Project::active()->each(fn(Project $project) => CaptureProjectSnapshotsJob::dispatch($project));
})->dailyAt('03:00')->name('capture-project-snapshots');
