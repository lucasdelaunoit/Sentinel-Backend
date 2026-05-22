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
 * Recompute project metrics (fragility + bus_factor). Delegates to
 * ProjectManager::recalculateProjectMetrics which writes BOTH the cache
 * columns and a snapshot row inside one transaction.
 *
 * Intentionally NOT ShouldBeUnique — every dispatch must produce a snapshot
 * row so trend history captures every change.
 */
class RecalculateProjectRiskJob implements ShouldQueue
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

        $projects->recalculateProjectMetrics($project);
    }
}
