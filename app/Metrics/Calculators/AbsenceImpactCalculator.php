<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Scales\AbsenceImpactScale;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;
use App\Models\User;
use App\Services\SkillCoverageService;

/**
 * Absence-impact metric — count of skills that flip to 'uncovered' once an absence roster is applied.
 *
 * Scopes:
 *  - forProject — newly-uncovered count for one project given a roster (or today's absences).
 *                 Persists projects.absence_impact_raw + snapshot.
 *  - forOrg     — org-wide count across active projects for today's absences.
 *                 Persists snapshot MetricKey::DashboardAbsenceImpact.
 */
class AbsenceImpactCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  CORE math. Diffs baseline vs with-absence matrices, counts newly-uncovered skills.
     * </summary>
     *
     * @param array $baseline Coverage matrix without the absence roster
     * @param array $withAbsence Coverage matrix with the roster applied
     * @return int
     */
    private function calculateCore(array $baseline, array $withAbsence): int
    {
        $count = 0;
        foreach ($withAbsence as $skillId => $simSkill) {
            if (
                $simSkill['status'] === 'uncovered' &&
                ($baseline[$skillId]['status'] ?? 'uncovered') !== 'uncovered'
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * <summary>
     *  Pure raw newly-uncovered count for a project. Empty roster → 0.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds Roster to project on top of baseline
     * @return int
     */
    public function computeRawForProject(Project $project, array $absentUserIds = []): int
    {
        if (empty($absentUserIds)) return 0;

        // Horizon 0 — the roster carries the absences; a horizon baseline would hide their impact.
        $baseline = $this->coverage->getCoverage($project, [], [], 0);
        $withAbsence = $this->coverage->getCoverage($project, $absentUserIds, [], 0);

        return $this->calculateCore($baseline, $withAbsence);
    }

    /**
     * <summary>
     *  Persist project absence-impact — updates projects.absence_impact_raw + appends snapshot.
     *  Roster defaults to today's absent users on the project's team if not provided.
     * </summary>
     *
     * @param Project $project
     * @param array<int>|null $absentUserIds Pass null to resolve today's absentees on the team
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forProject(Project $project, ?array $absentUserIds = null): MetricSnapshot
    {
        $absentUserIds ??= $this->getTodayAbsentUserIdsForProject($project);

        $count = $this->computeRawForProject($project, $absentUserIds);
        $stat = Stat::fromScale(AbsenceImpactScale::fromRaw($count), $count, $this->buildImpactInsight($count));

        return $this->metricsManager->persistProjectMetric($project, 'absence_impact_raw', MetricKey::AbsenceImpact, $stat);
    }

    /**
     * <summary>
     *  Persist org-wide dashboard absence-impact snapshot. Sums newly-uncovered across active projects
     *  given today's absent users (resolved inline).
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $absentUserIds = User::absentToday()->pluck('id')->all();

        if (empty($absentUserIds)) {
            return $this->metricsManager->persistOrgMetric(
                MetricKey::DashboardAbsenceImpact,
                Stat::fromScale(AbsenceImpactScale::fromRaw(0), 0, $this->buildImpactInsight(0)),
            );
        }

        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $count = 0;
        foreach ($projects as $project) {
            $count += $this->computeRawForProject($project, $absentUserIds);
        }

        return $this->metricsManager->persistOrgMetric(
            MetricKey::DashboardAbsenceImpact,
            Stat::fromScale(AbsenceImpactScale::fromRaw($count), $count, $this->buildImpactInsight($count)),
        );
    }

    private function buildImpactInsight(int $count): string
    {
        return $count > 0
            ? "{$count} skill" . ($count > 1 ? 's' : '') . ' became uncovered'
            : 'No impact from absences';
    }

    /**
     * @return array<int>
     */
    private function getTodayAbsentUserIdsForProject(Project $project): array
    {
        return $project->users()->absentToday()->pluck('users.id')->all();
    }
}
