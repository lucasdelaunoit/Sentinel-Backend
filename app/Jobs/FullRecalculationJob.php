<?php

namespace App\Jobs;

use App\Managers\DashboardManager;
use App\Managers\ProjectManager;
use App\Managers\UserManager;
use App\Metrics\Runs\CalculationRunService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The nightly (03:00) full metric cascade as ONE queued job with live progress:
 *   1. Per-project metrics (cache cols + project snapshots)
 *   2. Per-user metrics (cache cols + user snapshots)
 *   3. Org aggregates (projects → users → dashboard snapshots)
 *
 * Order matters — org calculators read the cached columns written in 1+2.
 * Runs sequentially on purpose: one run row gives honest step-by-step progress
 * for the dashboard sync card, and there is no fan-out coordination to break.
 * Single attempt — a partial cascade is fixed by the next trigger or night.
 */
class FullRecalculationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $calculationRunId,
    ) {}

    public function handle(
        ProjectManager $projectManager,
        UserManager $userManager,
        DashboardManager $dashboardManager,
        CalculationRunService $runs,
    ): void {
        $projects = Project::query()->whereNull('archived_at')->get();
        $users = User::query()->get();

        $runs->setRunTotals($this->calculationRunId, $projects->count() + $users->count() + 3);
        $runs->markRunRunning($this->calculationRunId);

        $processed = 0;

        try {
            foreach ($projects as $project) {
                $projectManager->recalculateProjectMetrics($project);
                $runs->advanceRunProgress($this->calculationRunId, ++$processed);
            }

            foreach ($users as $user) {
                $userManager->captureUserStatsSnapshots($user);
                $runs->advanceRunProgress($this->calculationRunId, ++$processed);
            }

            $projectManager->captureProjectsStatsSnapshots();
            $runs->advanceRunProgress($this->calculationRunId, ++$processed);

            $userManager->captureUsersStatsSnapshots();
            $runs->advanceRunProgress($this->calculationRunId, ++$processed);

            $dashboardManager->captureDashboardStatsSnapshots();
        } catch (Throwable $e) {
            $runs->markRunFailed($this->calculationRunId, $e->getMessage());
            throw $e;
        }

        $runs->markRunCompleted($this->calculationRunId);
    }
}
