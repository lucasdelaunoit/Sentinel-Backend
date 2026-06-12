<?php

namespace App\Metrics\Runs;

use App\Metrics\Snapshots\MetricScope;
use Illuminate\Support\Facades\DB;

/**
 * Single writer + reader for the calculation_runs table.
 *
 * Jobs report their lifecycle here (running → completed/failed, progress ticks);
 * CalculationRunManager reads it for debounce decisions and sync-status payloads.
 */
class CalculationRunService
{
    /**
     * <summary>
     *  True when a fresh queued run exists for the scope — the debounce check.
     *  Stale queued rows (older than the pending window) are ignored so a dead
     *  worker can never block future recalculations.
     * </summary>
     *
     * @param MetricScope $scope Scope of the run
     * @param int|null $scopeId Entity id (null when scope = Org)
     * @return bool
     */
    public function hasPendingRun(MetricScope $scope, ?int $scopeId): bool
    {
        return CalculationRun::query()
            ->forScope($scope, $scopeId)
            ->where('status', CalculationRunStatus::Queued->value)
            ->where('queued_at', '>', now()->subMinutes(CalculationRun::PENDING_WINDOW_MINUTES))
            ->exists();
    }

    /**
     * <summary>
     *  Insert a new queued run row for the scope. The row is the debounce lock
     *  until the job picks it up.
     * </summary>
     *
     * @param MetricScope $scope Scope of the run
     * @param int|null $scopeId Entity id (null when scope = Org)
     * @param int $totalItems Number of progress steps the run will report
     * @return CalculationRun Newly created row
     */
    public function createQueuedRun(MetricScope $scope, ?int $scopeId, int $totalItems = 1): CalculationRun
    {
        return CalculationRun::create([
            'scope_type' => $scope->value,
            'scope_id' => $scopeId,
            'status' => CalculationRunStatus::Queued->value,
            'total_items' => $totalItems,
            'queued_at' => now(),
        ]);
    }

    /**
     * <summary>
     *  Overwrite total_items once the real step count is known (full recalc counts
     *  its entities only when the job starts).
     * </summary>
     *
     * @param int $runId Target run id
     * @param int $totalItems Real number of progress steps
     * @return void
     */
    public function setRunTotals(int $runId, int $totalItems): void
    {
        CalculationRun::query()->whereKey($runId)->update(['total_items' => $totalItems]);
    }

    /**
     * <summary>
     *  Mark a run as running (job picked up). Resets error so a retried job
     *  re-enters a clean running state.
     * </summary>
     *
     * @param int $runId Target run id
     * @return void
     */
    public function markRunRunning(int $runId): void
    {
        CalculationRun::query()->whereKey($runId)->update([
            'status' => CalculationRunStatus::Running->value,
            'started_at' => now(),
            'error' => null,
        ]);
    }

    /**
     * <summary>
     *  Report progress on a running run.
     * </summary>
     *
     * @param int $runId Target run id
     * @param int $processedItems Steps completed so far
     * @return void
     */
    public function advanceRunProgress(int $runId, int $processedItems): void
    {
        CalculationRun::query()->whereKey($runId)->update(['processed_items' => $processedItems]);
    }

    /**
     * <summary>
     *  Mark a run completed — processed snaps to total, finished_at set.
     * </summary>
     *
     * @param int $runId Target run id
     * @return void
     */
    public function markRunCompleted(int $runId): void
    {
        CalculationRun::query()->whereKey($runId)->update([
            'status' => CalculationRunStatus::Completed->value,
            'processed_items' => DB::raw('total_items'),
            'finished_at' => now(),
        ]);
    }

    /**
     * <summary>
     *  Mark a run failed and store the error message (truncated).
     * </summary>
     *
     * @param int $runId Target run id
     * @param string $error Exception message from the job
     * @return void
     */
    public function markRunFailed(int $runId, string $error): void
    {
        CalculationRun::query()->whereKey($runId)->update([
            'status' => CalculationRunStatus::Failed->value,
            'error' => mb_substr($error, 0, 500),
            'finished_at' => now(),
        ]);
    }

    /**
     * <summary>
     *  Most recent run for a scope, any status. Drives the sync-status state.
     * </summary>
     *
     * @param MetricScope $scope Scope of the run
     * @param int|null $scopeId Entity id (null when scope = Org)
     * @return CalculationRun|null
     */
    public function getLatestRun(MetricScope $scope, ?int $scopeId): ?CalculationRun
    {
        return CalculationRun::query()
            ->forScope($scope, $scopeId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * <summary>
     *  Most recent completed run for a scope — its finished_at is "last calculated at".
     * </summary>
     *
     * @param MetricScope $scope Scope of the run
     * @param int|null $scopeId Entity id (null when scope = Org)
     * @return CalculationRun|null
     */
    public function getLatestCompletedRun(MetricScope $scope, ?int $scopeId): ?CalculationRun
    {
        return CalculationRun::query()
            ->forScope($scope, $scopeId)
            ->where('status', CalculationRunStatus::Completed->value)
            ->orderByDesc('finished_at')
            ->first();
    }
}
