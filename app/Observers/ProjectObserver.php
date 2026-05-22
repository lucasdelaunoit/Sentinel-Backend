<?php

namespace App\Observers;

use App\Jobs\CaptureProjectSnapshotsJob;
use App\Models\Project;

/**
 * Reacts to Project mutations.
 *
 * Current scope: dispatch a metric-snapshot capture when the precomputed
 * fragility_raw or bus_factor columns change. RecalculateProjectRiskJob is
 * what mutates those columns, so a snapshot row lands shortly after every
 * real recalculation.
 *
 * The job is dispatched (queued) — observer must stay cheap.
 */
class ProjectObserver
{
    public function updated(Project $project): void
    {
        $changed = $project->wasChanged(['fragility_raw', 'bus_factor']);
        if (!$changed) return;

        CaptureProjectSnapshotsJob::dispatch($project);
    }

    public function created(Project $project): void
    {
        CaptureProjectSnapshotsJob::dispatch($project);
    }
}
