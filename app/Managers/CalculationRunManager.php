<?php

namespace App\Managers;

use App\DTO\CalculationSyncStatus;
use App\Jobs\FullRecalculationJob;
use App\Jobs\RecalculateOrgMetricsJob;
use App\Jobs\RecalculateProjectMetricsJob;
use App\Jobs\RecalculateUserMetricsJob;
use App\Metrics\Runs\CalculationRun;
use App\Metrics\Runs\CalculationRunService;
use App\Metrics\Runs\CalculationRunStatus;
use App\Metrics\Snapshots\MetricScope;
use App\Models\Project;
use App\Models\User;

/**
 * Single entry point for queueing recalculations and reading their sync status.
 *
 * Debounce: every queue method first checks for a fresh queued run on the same
 * scope — if one exists the trigger is dropped, because the pending (delayed)
 * job reads fresh data when it executes and therefore already covers the change.
 * The run row itself is the lock; no cache locks involved.
 */
class CalculationRunManager
{
    private const DEBOUNCE_ENTITY_SECONDS = 10;
    private const DEBOUNCE_ORG_SECONDS = 30;

    public function __construct(
        private readonly CalculationRunService $calculationRunService,
    ) {}

    /**
     * <summary>
     *  Queue a debounced metrics recalculation for one project. Creates a queued
     *  CalculationRun and dispatches RecalculateProjectMetricsJob with a short delay;
     *  no-op when a fresh queued run already exists for the project. Also queues
     *  the org-aggregates refresh so the dashboard sync card reacts immediately.
     * </summary>
     *
     * @param Project $project Target project
     * @return void
     */
    public function queueProjectRecalculation(Project $project): void
    {
        if ($this->calculationRunService->hasPendingRun(MetricScope::Project, $project->id)) {
            return;
        }

        $run = $this->calculationRunService->createQueuedRun(MetricScope::Project, $project->id);

        RecalculateProjectMetricsJob::dispatch($project, $run->id)
            ->delay(now()->addSeconds(self::DEBOUNCE_ENTITY_SECONDS));

        $this->queueOrgAggregatesRecalculation();
    }

    /**
     * <summary>
     *  Queue a debounced metrics recalculation for one user (criticality, bus factor
     *  in org, skill counts). Same debounce mechanics as the project variant, org
     *  refresh included.
     * </summary>
     *
     * @param User $user Target user
     * @return void
     */
    public function queueUserRecalculation(User $user): void
    {
        if ($this->calculationRunService->hasPendingRun(MetricScope::User, $user->id)) {
            return;
        }

        $run = $this->calculationRunService->createQueuedRun(MetricScope::User, $user->id);

        RecalculateUserMetricsJob::dispatch($user, $run->id)
            ->delay(now()->addSeconds(self::DEBOUNCE_ENTITY_SECONDS));

        $this->queueOrgAggregatesRecalculation();
    }

    /**
     * <summary>
     *  Queue a debounced refresh of the org-scope aggregates (projects, users,
     *  dashboard snapshots). Called by entity jobs after they finish so the
     *  dashboard follows entity changes; the longer delay batches bursts.
     * </summary>
     *
     * @return void
     */
    public function queueOrgAggregatesRecalculation(): void
    {
        if ($this->calculationRunService->hasPendingRun(MetricScope::Org, null)) {
            return;
        }

        $run = $this->calculationRunService->createQueuedRun(MetricScope::Org, null, 3);

        RecalculateOrgMetricsJob::dispatch($run->id)
            ->delay(now()->addSeconds(self::DEBOUNCE_ORG_SECONDS));
    }

    /**
     * <summary>
     *  Queue the nightly full cascade (every project → every user → org aggregates)
     *  as one org-scope run with step-by-step progress. The job sets the real
     *  total once it has counted the entities. No-op when an org run is pending.
     * </summary>
     *
     * @return void
     */
    public function queueFullRecalculation(): void
    {
        if ($this->calculationRunService->hasPendingRun(MetricScope::Org, null)) {
            return;
        }

        $run = $this->calculationRunService->createQueuedRun(MetricScope::Org, null, 3);

        FullRecalculationJob::dispatch($run->id);
    }

    /**
     * <summary>
     *  Assemble the sync-status payload for a scope: current state (idle / queued /
     *  running / failed), last successful calculation time, and live progress while
     *  running. Stale queued rows degrade to idle.
     * </summary>
     *
     * @param MetricScope $scope Scope to report on
     * @param int|null $scopeId Entity id (null when scope = Org)
     * @return CalculationSyncStatus
     */
    public function getSyncStatus(MetricScope $scope, ?int $scopeId): CalculationSyncStatus
    {
        $latest = $this->calculationRunService->getLatestRun($scope, $scopeId);
        $lastCompleted = $this->calculationRunService->getLatestCompletedRun($scope, $scopeId);

        $state = $this->resolveState($latest);
        $isRunning = $state === CalculationRunStatus::Running->value;

        return new CalculationSyncStatus(
            state: $state,
            lastCalculatedAt: $lastCompleted?->finished_at,
            processedItems: $isRunning ? $latest->processed_items : null,
            totalItems: $isRunning ? $latest->total_items : null,
            error: $state === CalculationRunStatus::Failed->value ? $latest?->error : null,
        );
    }

    /**
     * <summary>
     *  Map the latest run to a display state. Completed (or no run yet, or a queued
     *  row gone stale) reads as idle — "nothing in flight, data is what it is".
     * </summary>
     *
     * @param CalculationRun|null $latest Most recent run for the scope
     * @return string idle | queued | running | failed
     */
    private function resolveState(?CalculationRun $latest): string
    {
        if ($latest === null) {
            return 'idle';
        }

        return match ($latest->status) {
            CalculationRunStatus::Queued->value => $latest->isPending() ? 'queued' : 'idle',
            CalculationRunStatus::Running->value => 'running',
            CalculationRunStatus::Failed->value => 'failed',
            default => 'idle',
        };
    }
}
