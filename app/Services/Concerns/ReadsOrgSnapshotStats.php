<?php

namespace App\Services\Concerns;

use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricScope;
use App\Metrics\Stat;

/**
 * Shared org-snapshot read helper for Services exposing precomputed stats.
 * Host class must define a `snapshotService` property (MetricSnapshotService).
 */
trait ReadsOrgSnapshotStats
{
    /**
     * <summary>
     *  Read the latest org-scope snapshot for the given metric key and rehydrate it as a Stat.
     *  Returns a placeholder Stat when no snapshot has been captured yet.
     * </summary>
     *
     * @param MetricKey $metric Snapshot key to read
     * @return Stat
     */
    private function readOrgSnapshotStat(MetricKey $metric): Stat
    {
        $snap = $this->snapshotService->latestFor(MetricScope::Org, null, $metric);

        return $snap !== null ? Stat::fromSnapshot($snap) : Stat::placeholder();
    }
}
