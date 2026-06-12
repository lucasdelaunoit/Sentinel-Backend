<?php

namespace App\Jobs;

use App\Managers\DashboardManager;
use App\Managers\ProjectManager;
use App\Managers\UserManager;
use App\Metrics\Runs\CalculationRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Refresh the org-scope aggregate snapshots (projects stats, users stats,
 * dashboard stats). Cheap — the org calculators read the cached per-entity
 * columns, so this MUST run after the per-entity jobs that changed them
 * (guaranteed by being queued from those jobs' handle(), with a delay).
 *
 * Debounced upstream by CalculationRunManager; 3 progress steps, one per
 * capture call.
 */
class RecalculateOrgMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $calculationRunId,
    ) {}

    public function handle(
        ProjectManager $projects,
        UserManager $users,
        DashboardManager $dashboard,
        CalculationRunService $runs,
    ): void {
        $runs->markRunRunning($this->calculationRunId);

        try {
            $projects->captureProjectsStatsSnapshots();
            $runs->advanceRunProgress($this->calculationRunId, 1);

            $users->captureUsersStatsSnapshots();
            $runs->advanceRunProgress($this->calculationRunId, 2);

            $dashboard->captureDashboardStatsSnapshots();
        } catch (Throwable $e) {
            $runs->markRunFailed($this->calculationRunId, $e->getMessage());
            throw $e;
        }

        $runs->markRunCompleted($this->calculationRunId);
    }
}
