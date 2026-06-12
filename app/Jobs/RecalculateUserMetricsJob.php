<?php

namespace App\Jobs;

use App\Managers\CalculationRunManager;
use App\Managers\UserManager;
use App\Metrics\Runs\CalculationRunService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Recompute user-scope metrics (criticality, bus factor in org, skill counts)
 * via UserManager::captureUserStatsSnapshots — cache columns + snapshot rows.
 *
 * Debounced upstream by CalculationRunManager (pending-run check + delayed
 * dispatch). Reports its lifecycle to the CalculationRun row it was dispatched
 * with, then queues the org-aggregates refresh so dashboard stats follow.
 */
class RecalculateUserMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly User $user,
        public readonly ?int $calculationRunId = null,
    ) {}

    public function handle(UserManager $users, CalculationRunService $runs): void
    {
        $user = $this->user->fresh();
        if ($user === null) {
            if ($this->calculationRunId !== null) {
                $runs->markRunCompleted($this->calculationRunId);
            }
            return;
        }

        if ($this->calculationRunId !== null) {
            $runs->markRunRunning($this->calculationRunId);
        }

        try {
            $users->captureUserStatsSnapshots($user);
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
