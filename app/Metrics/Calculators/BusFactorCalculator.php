<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Scales\BusFactorScale;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;
use App\Models\User;
use App\Services\SkillCoverageService;

/**
 * Bus-factor metric — multi-scope. CORE math operates on a coverage matrix.
 *
 * Scopes:
 *  - forProject — min covered count across required skills. Persists projects.bus_factor + snapshot.
 *  - forUser    — count of active projects where the user pushes bus factor &lt;= 2. Persists users.bus_factor_in_org_raw + snapshot.
 *
 * compute*Raw methods are pure (no side effect) — used by sibling Calculators that need
 * the value without triggering persistence (e.g. FragilityCalculator consumes the project raw).
 */
class BusFactorCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  CORE math. Min covered count across required skills. 0 when any required skill is uncovered.
     * </summary>
     *
     * @param array $coverageMatrix Output of SkillCoverageService::getCoverage
     * @return int
     */
    private function calculateCore(array $coverageMatrix): int
    {
        $coveredCounts = [];
        foreach ($coverageMatrix as $row) {
            $c = count($row['employees']);
            if ($c > 0) $coveredCounts[] = $c;
        }

        if ($coveredCounts === []) return 0;
        return min($coveredCounts);
    }

    /**
     * <summary>
     *  Pure raw bus factor for a project. No DB writes. Accepts virtual absence roster for simulation
     *  and a present-override roster (users forced available, overriding their real horizon-absence).
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @param array<int> $presentUserIds Users forced present (clean baseline isolation)
     * @return int
     */
    public function computeRawForProject(Project $project, array $absentUserIds = [], array $presentUserIds = []): int
    {
        return $this->calculateCore($this->coverage->getCoverage($project, $absentUserIds, $presentUserIds));
    }

    /**
     * <summary>
     *  Persist project bus_factor — updates projects.bus_factor + appends snapshot. Single transaction.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds Simulation roster — leave empty for live state
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forProject(Project $project, array $absentUserIds = []): MetricSnapshot
    {
        $bf = $this->computeRawForProject($project, $absentUserIds);
        $stat = Stat::fromScale(
            BusFactorScale::fromCount($bf),
            $bf,
            $bf > 0 ? "{$bf} key " . ($bf === 1 ? 'person' : 'people') : 'No coverage',
        );

        return $this->metricsManager->persistProjectMetric($project, 'bus_factor', MetricKey::BusFactor, $stat);
    }

    /**
     * <summary>
     *  Pure compute — count of active projects where the user is on the team AND project bus_factor &lt;= 2.
     * </summary>
     *
     * @param User $user
     * @return int
     */
    public function computeRawForUser(User $user): int
    {
        $user->loadMissing('projects');
        $now = now();

        return $user->projects
            ->filter(fn(Project $p) => $p->started_at !== null
                && $p->started_at <= $now
                && $p->paused_at === null
                && $p->completed_at === null
                && $p->archived_at === null
                && $this->computeRawForProject($p) <= 2
            )
            ->count();
    }

    /**
     * <summary>
     *  Persist user bus-factor-in-org — updates users.bus_factor_in_org_raw + appends snapshot.
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
            value: $count === 0 ? 'Safe' : (string) $count,
            valueRaw: $count,
            severity: $count > 0 ? \App\Metrics\Severity::CRITICAL : \App\Metrics\Severity::OK,
            insight: $count > 0
                ? "{$count} project" . ($count > 1 ? 's' : '') . ' at risk'
                : 'No single-point exposure',
        );

        return $this->metricsManager->persistUserMetric($user, 'bus_factor_in_org_raw', MetricKey::BusFactorInOrg, $stat);
    }
}
