<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\User;

/**
 * User-active-projects metric — count of active projects the user is assigned to. Live query (no cached col).
 * Snapshot only (for history). User-scope only.
 */
class UserActiveProjectsCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * @param User $user
     * @return int
     */
    public function computeRawForUser(User $user): int
    {
        return $user->projects()
            ->whereNotNull('started_at')
            ->whereDate('started_at', '<=', now())
            ->whereNull('paused_at')
            ->whereNull('completed_at')
            ->whereNull('archived_at')
            ->count();
    }

    /**
     * <summary>
     *  Persist user active-projects snapshot.
     * </summary>
     *
     * @param User $user
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forUser(User $user): MetricSnapshot
    {
        $count = $this->computeRawForUser($user);
        $stat = new Stat(
            value: $count === 0 ? 'None' : (string) $count,
            valueRaw: $count,
            severity: Severity::OK,
            insight: $count > 0 ? 'Assigned' : null,
        );

        return $this->metricsManager->persistUserMetric($user, null, MetricKey::ActiveProjects, $stat);
    }
}
