<?php

namespace App\Managers;

use App\DTO\Stats\DashboardStats;
use App\Metrics\Calculators\AbsenceImpactCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Metrics\Calculators\KnowledgeCoverageCalculator;
use App\Metrics\Calculators\TeamAvailabilityCalculator;
use App\Services\ProjectService;
use App\Services\UserService;

class DashboardManager
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly UserService $userService,
        private readonly FragilityCalculator $fragilityCalculator,
        private readonly KnowledgeCoverageCalculator $knowledgeCoverageCalculator,
        private readonly TeamAvailabilityCalculator $teamAvailabilityCalculator,
        private readonly AbsenceImpactCalculator $absenceImpactCalculator,
    ) {}

    /**
     * <summary>
     *  Assemble the typed DashboardStats DTO for GET /dashboard/stats.
     *  Orchestrates ProjectService + UserService — one Service call per metric.
     *  Calculators are only retained for the /stats/* detail drilldown routes.
     * </summary>
     *
     * @return DashboardStats fragile_projects, knowledge_coverage, team_availability, absence_impact
     */
    public function getTodayStats(): DashboardStats
    {
        $absentIds = $this->userService->getAbsentUserIdsToday();

        return new DashboardStats(
            fragileProjects: $this->projectService->getWorstFragilityStat(),
            knowledgeCoverage: $this->projectService->getKnowledgeCoverageStat(),
            teamAvailability: $this->userService->getTeamAvailabilityStat(),
            absenceImpact: $this->projectService->getAbsenceImpactStat($absentIds),
        );
    }

    /**
     * <summary>
     *  Drilldown for the fragile-projects KPI — critical + unstable project buckets with missing / siloed skills per project.
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
     *  Drilldown for the knowledge-coverage KPI — coverage breakdown grouped by skill category, sorted by lowest coverage first.
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
     *  Drilldown for the team-availability KPI — absent users with criticality and degraded active projects.
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
     *  Drilldown for the absence-impact KPI — every skill that became uncovered due to today's active absences.
     * </summary>
     *
     * @return array{uncovered_skills: array<int, array>}
     */
    public function getAbsenceImpactDetail(): array
    {
        return $this->absenceImpactCalculator->detail();
    }
}
