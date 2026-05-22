<?php

namespace App\Managers;

use App\DTO\Stats\DashboardStats;
use App\Services\ProjectService;
use App\Services\UserService;

class DashboardManager
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly UserService $userService,
    ) {}

    /**
     * <summary>
     *  Assemble the typed DashboardStats DTO for GET /dashboard/stats.
     *  Orchestrates ProjectService + UserService — one Service call per metric.
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
}
