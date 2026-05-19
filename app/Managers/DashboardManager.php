<?php

namespace App\Managers;

use App\Metrics\AbsenceImpactScale;
use App\Metrics\Calculators\AbsenceImpactCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Metrics\Calculators\KnowledgeCoverageCalculator;
use App\Metrics\Calculators\TeamAvailabilityCalculator;
use App\Metrics\FragilityScale;
use App\Metrics\KnowledgeCoverageScale;
use App\Metrics\TeamAvailabilityScale;
use App\Support\Stat;

class DashboardManager
{
    public function __construct(
        private readonly FragilityCalculator $fragilityCalculator,
        private readonly KnowledgeCoverageCalculator $knowledgeCoverageCalculator,
        private readonly TeamAvailabilityCalculator $teamAvailabilityCalculator,
        private readonly AbsenceImpactCalculator $absenceImpactCalculator,
    ) {}

    /**
     * <summary>
     *  Assemble the four headline KPIs shown on the dashboard. Each value is a Stat DTO
     *  (display label + raw + severity + insight) — the Resource just calls toArray().
     * </summary>
     *
     * @return array<string, Stat>
     */
    public function getTodayStats(): array
    {
        return [
            'fragile_projects' => $this->fragileProjectsStat(),
            'knowledge_coverage' => $this->knowledgeCoverageStat(),
            'team_availability' => $this->teamAvailabilityStat(),
            'absence_impact' => $this->absenceImpactStat(),
        ];
    }

    /**
     * <summary>
     *  Drilldown for the fragile-projects KPI — critical + unstable project buckets
     *  with missing / siloed skills per project.
     * </summary>
     *
     * @return array{critical: array<int, array>, unstable: array<int, array>}
     */
    public function getProjectsAtRiskDetail(): array
    {
        return $this->fragilityCalculator->detail();
    }

    /**
     * <summary>
     *  Drilldown for the knowledge-coverage KPI — coverage breakdown grouped by skill category,
     *  sorted by lowest coverage first.
     * </summary>
     *
     * @return array{categories: array<int, array>, most_fragile: ?string}
     */
    public function getKnowledgeCoverageDetail(): array
    {
        return $this->knowledgeCoverageCalculator->detail();
    }

    /**
     * <summary>
     *  Drilldown for the team-availability KPI — absent users (with criticality)
     *  and the active projects whose coverage degraded because of them.
     * </summary>
     *
     * @return array{absent_employees: array<int, array>, at_risk_projects: array<int, array>}
     */
    public function getTeamAvailabilityDetail(): array
    {
        return $this->teamAvailabilityCalculator->detail();
    }

    /**
     * <summary>
     *  Drilldown for the absence-impact KPI — every skill that became uncovered
     *  due to today's active absences, with project and previous coverers.
     * </summary>
     *
     * @return array{uncovered_skills: array<int, array>}
     */
    public function getAbsenceImpactDetail(): array
    {
        return $this->absenceImpactCalculator->detail();
    }

    private function fragileProjectsStat(): Stat
    {
        $r = $this->fragilityCalculator->kpi();
        return Stat::fromScale(FragilityScale::fromRaw($r['raw']), $r['raw'], $r['insight']);
    }

    private function knowledgeCoverageStat(): Stat
    {
        $r = $this->knowledgeCoverageCalculator->kpi();
        return Stat::display(
            "{$r['raw']}%",
            $r['raw'],
            KnowledgeCoverageScale::fromRaw($r['raw']),
            $r['insight'],
        );
    }

    private function teamAvailabilityStat(): Stat
    {
        $r = $this->teamAvailabilityCalculator->kpi();
        return Stat::display(
            "{$r['available']}/{$r['total']}",
            $r['available'],
            TeamAvailabilityScale::fromCounts($r['absent'], $r['critical_absent']),
            $r['insight'],
        );
    }

    private function absenceImpactStat(): Stat
    {
        $r = $this->absenceImpactCalculator->kpi();
        return Stat::fromScale(AbsenceImpactScale::fromRaw($r['raw']), $r['raw'], $r['insight']);
    }
}
