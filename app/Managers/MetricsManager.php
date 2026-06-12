<?php

namespace App\Managers;

use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricScope;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Snapshots\MetricSnapshotService;
use App\Metrics\Stat;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single writer for metric persistence — atomic (cached column update + snapshot write) per call.
 * Calculators delegate persistence here so the transaction + drift-prevention logic lives in one place.
 */
class MetricsManager
{
    public function __construct(
        private readonly MetricSnapshotService $snapshotsService,
    ) {}

    /**
     * <summary>
     *  Persist a per-project metric in a single transaction. Updates an optional cached column
     *  (column name on the projects table) and appends one MetricSnapshot row.
     *  Pass null cachedColumn for metrics without a cache (snapshot-only).
     * </summary>
     *
     * @param Project $project Target project
     * @param string|null $cachedColumn Column on projects table to update with $stat->valueRaw, or null
     * @param MetricKey $key Snapshot key
     * @param Stat $stat Built Stat — supplies value_raw, label, severity, insight
     * @return MetricSnapshot Newly written row
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function persistProjectMetric(
        Project $project,
        ?string $cachedColumn,
        MetricKey $key,
        Stat $stat,
    ): MetricSnapshot {
        return $this->persistEntityMetric($project, MetricScope::Project, $cachedColumn, $key, $stat);
    }

    /**
     * <summary>
     *  Persist a per-user metric in a single transaction. Updates an optional cached column
     *  (column name on the users table) and appends one MetricSnapshot row.
     * </summary>
     *
     * @param User $user Target user
     * @param string|null $cachedColumn Column on users table to update with $stat->valueRaw, or null
     * @param MetricKey $key Snapshot key
     * @param Stat $stat Built Stat
     * @return MetricSnapshot Newly written row
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function persistUserMetric(
        User $user,
        ?string $cachedColumn,
        MetricKey $key,
        Stat $stat,
    ): MetricSnapshot {
        return $this->persistEntityMetric($user, MetricScope::User, $cachedColumn, $key, $stat);
    }

    /**
     * <summary>
     *  Shared persistence path for entity-scoped metrics: one transaction that updates the
     *  optional cached column on the entity row and appends one MetricSnapshot.
     * </summary>
     *
     * @param Model $entity Target Project or User row
     * @param MetricScope $scope Snapshot scope matching the entity type
     * @param string|null $cachedColumn Column on the entity table to update with $stat->valueRaw, or null
     * @param MetricKey $key Snapshot key
     * @param Stat $stat Built Stat
     * @return MetricSnapshot Newly written row
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    private function persistEntityMetric(
        Model $entity,
        MetricScope $scope,
        ?string $cachedColumn,
        MetricKey $key,
        Stat $stat,
    ): MetricSnapshot {
        return DB::transaction(function () use ($entity, $scope, $cachedColumn, $key, $stat) {
            if ($cachedColumn !== null) {
                $entity->update([$cachedColumn => $stat->valueRaw]);
            }
            return $this->snapshotsService->captureSnapshot($scope, $entity->id, $key, $stat);
        });
    }

    /**
     * <summary>
     *  Persist a single org-scope metric snapshot (no cached column — org has no entity row).
     * </summary>
     *
     * @param MetricKey $key Snapshot key
     * @param Stat $stat Built Stat
     * @return MetricSnapshot Newly written row
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function persistOrgMetric(MetricKey $key, Stat $stat): MetricSnapshot
    {
        return DB::transaction(fn() => $this->snapshotsService->captureSnapshot(MetricScope::Org, null, $key, $stat));
    }

    /**
     * <summary>
     *  Batch-persist multiple org-scope metric snapshots in a single transaction.
     *  Use when a Calculator produces several related org snapshots at once (e.g. FragilityCalculator::forOrg).
     * </summary>
     *
     * @param array<array{0: MetricKey, 1: Stat}> $pairs List of [MetricKey, Stat] tuples
     * @return Collection<int, MetricSnapshot> Newly written rows in insertion order
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function persistOrgMetrics(array $pairs): Collection
    {
        return DB::transaction(function () use ($pairs) {
            $rows = collect();
            foreach ($pairs as [$key, $stat]) {
                $rows->push($this->snapshotsService->captureSnapshot(MetricScope::Org, null, $key, $stat));
            }
            return $rows;
        });
    }
}
