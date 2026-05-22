<?php

namespace App\Jobs;

use App\Managers\ProjectManager;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Capture point-in-time metric snapshots for a project (fragility + bus_factor for now).
 *
 * Dispatched by:
 *  - ProjectObserver::updated when fragility_raw / bus_factor change (real-time row).
 *  - Cron in routes/console.php once a day for every active project (continuous history).
 *
 * The job is intentionally NOT ShouldBeUnique — we want every dispatch to land
 * its own row so trend history captures every change, not the last one only.
 */
class CaptureProjectSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Project $project,
    ) {}

    public function handle(ProjectManager $projects): void
    {
        $project = $this->project->fresh();
        if ($project === null) return;

        $projects->captureProjectSnapshots($project);
    }
}
