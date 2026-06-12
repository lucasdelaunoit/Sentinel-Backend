<?php

namespace App\Jobs;

use App\Managers\CalculationRunManager;
use App\Managers\ProjectManager;
use App\Metrics\Runs\CalculationRunService;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Recompute project metrics (fragility + bus_factor). Delegates to
 * ProjectManager::recalculateProjectMetrics which writes BOTH the cache
 * columns and a snapshot row inside one transaction.
 *
 * Debounced upstream by CalculationRunManager (pending-run check + delayed
 * dispatch) so mutation bursts collapse into one run; every run that executes
 * still appends snapshot rows, so trend history stays continuous.
 * Reports its lifecycle to the CalculationRun row it was dispatched with,
 * then queues the org-aggregates refresh so dashboard stats follow.
 */
class RecalculateProjectMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Project $project,
        public readonly ?int $calculationRunId = null,
    ) {}

    public function handle(ProjectManager $projects, CalculationRunService $runs): void
    {
        $project = $this->project->fresh();
        if ($project === null) {
            if ($this->calculationRunId !== null) {
                $runs->markRunCompleted($this->calculationRunId);
            }
            return;
        }

        if ($this->calculationRunId !== null) {
            $runs->markRunRunning($this->calculationRunId);
        }

        try {
            $projects->recalculateProjectMetrics($project);
        } catch (Throwable $e) {
            if ($this->calculationRunId !== null) {
                $runs->markRunFailed($this->calculationRunId, $e->getMessage());
            }
            throw $e;
        }

        if ($this->calculationRunId !== null) {
            $runs->markRunCompleted($this->calculationRunId);
        }

        app(CalculationRunManager::class)->queueOrgAggregatesRecalculation();
    }
}
