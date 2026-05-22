<?php

namespace App\Services;

use App\Metrics\MetricKey;
use App\Metrics\MetricScope;
use App\Metrics\Stat;
use App\Models\MetricSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Writer + reader API for the metric_snapshots time-series table.
 *
 * Writer is wired now (cron + observer). Reader methods (latestFor / trendFor)
 * exist as stubs so callers (stats endpoints, frontend trend lines) have a
 * stable API to bind against once we start consuming snapshots.
 */
class MetricSnapshotService
{
    /**
     * <summary>
     *  Persist a single Stat as a new metric_snapshots row. Never updates — every call appends.
     *  Caller supplies the scope (project/user/org/...) + which metric the Stat represents.
     * </summary>
     *
     * @param MetricScope $scope Scope of the snapshot
     * @param int|null $scopeId Entity id (null when scope = Org)
     * @param MetricKey $metric Which metric the Stat measures
     * @param Stat $stat Built Stat — supplies value_raw, value_label, severity, insight
     * @param Carbon|null $capturedAt When the snapshot was taken (defaults to now)
     * @return MetricSnapshot Newly written row
     */
    public function captureSnapshot(
        MetricScope $scope,
        ?int $scopeId,
        MetricKey $metric,
        Stat $stat,
        ?Carbon $capturedAt = null,
    ): MetricSnapshot {
        return MetricSnapshot::create([
            'scope_type' => $scope->value,
            'scope_id' => $scopeId,
            'metric' => $metric->value,
            'value_raw' => (float) $stat->valueRaw,
            'value_label' => $stat->value,
            'severity' => $stat->severity->value,
            'meta' => $stat->insight !== null ? ['insight' => $stat->insight] : null,
            'captured_at' => $capturedAt ?? now(),
        ]);
    }

    /**
     * <summary>
     *  Read API stub — most recent snapshot for (scope, metric). Not wired into consumers yet.
     * </summary>
     *
     * @param MetricScope $scope
     * @param int|null $scopeId
     * @param MetricKey $metric
     * @return MetricSnapshot|null
     */
    public function latestFor(MetricScope $scope, ?int $scopeId, MetricKey $metric): ?MetricSnapshot
    {
        return MetricSnapshot::query()
            ->forScope($scope, $scopeId)
            ->forMetric($metric)
            ->orderByDesc('captured_at')
            ->first();
    }

    /**
     * <summary>
     *  Read API stub — snapshot rows in the last N days for (scope, metric), oldest first. Not wired yet.
     * </summary>
     *
     * @param MetricScope $scope
     * @param int|null $scopeId
     * @param MetricKey $metric
     * @param int $days Lookback window in days
     * @return Collection<int, MetricSnapshot>
     */
    public function trendFor(MetricScope $scope, ?int $scopeId, MetricKey $metric, int $days)
    {
        return MetricSnapshot::query()
            ->forScope($scope, $scopeId)
            ->forMetric($metric)
            ->where('captured_at', '>=', now()->subDays($days))
            ->orderBy('captured_at')
            ->get();
    }
}
