<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Scales\TeamAvailabilityScale;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;
use App\Models\User;

/**
 * Team-availability metric — % of team members currently available (not absent today / not in virtual roster).
 *
 * Scopes:
 *  - forProject — project team availability %. Persists projects.team_availability_raw + snapshot.
 *  - forOrg     — org-wide users availability %. Persists snapshot MetricKey::DashboardTeamAvailability.
 */
class TeamAvailabilityCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  CORE math. Returns 100.0 when total == 0 (vacuously full).
     * </summary>
     *
     * @param int $total Team headcount
     * @param int $absent Absent headcount (subset of total)
     * @return float 0-100
     */
    private function calculateCore(int $total, int $absent): float
    {
        if ($total === 0) return 100.0;
        return (($total - $absent) / $total) * 100;
    }

    /**
     * <summary>
     *  Pure raw % available for a project's team. Counts users in $absentUserIds OR currently absent today.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return float
     */
    public function computeRawForProject(Project $project, array $absentUserIds = []): float
    {
        $project->loadMissing('users.absences');
        $total = $project->users->count();
        $today = now()->toDateString();

        $absent = $project->users->filter(function (User $u) use ($today, $absentUserIds) {
            if (in_array($u->id, $absentUserIds, true)) return true;
            return $u->absences->contains(fn($a) => $a->start_date <= $today && $a->end_date >= $today);
        })->count();

        return $this->calculateCore($total, $absent);
    }

    /**
     * <summary>
     *  Persist project team-availability — updates projects.team_availability_raw + appends snapshot.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forProject(Project $project, array $absentUserIds = []): MetricSnapshot
    {
        $raw = (int) round($this->computeRawForProject($project, $absentUserIds));
        $stat = Stat::fromScale(TeamAvailabilityScale::fromRaw($raw), $raw, "{$raw}% available");

        return $this->metricsManager->persistProjectMetric($project, 'team_availability_raw', MetricKey::TeamAvailability, $stat);
    }

    /**
     * <summary>
     *  Pure raw org-wide availability %. Today's absentees vs total user count.
     * </summary>
     *
     * @return float
     */
    public function computeRawForOrg(): float
    {
        $today = now()->toDateString();
        $total = User::count();
        $absent = User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->count();

        return $this->calculateCore($total, $absent);
    }

    /**
     * <summary>
     *  Persist org-scope dashboard team-availability snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $today = now()->toDateString();
        $total = User::count();
        $absent = User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->count();
        $available = $total - $absent;
        $pct = (int) round($this->calculateCore($total, $absent));

        $insight = $absent > 0
            ? "{$absent} employee" . ($absent > 1 ? 's' : '') . ' absent'
            : 'Fully operational';

        $stat = Stat::display(
            "{$available}/{$total}",
            $pct,
            TeamAvailabilityScale::fromRaw($pct),
            $insight,
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::DashboardTeamAvailability, $stat);
    }
}
