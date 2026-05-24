<?php

namespace App\Managers;

use App\DTO\Stats\DashboardStats;
use App\Metrics\Snapshots\MetricSnapshotService;
use App\Services\ProjectService;
use App\Services\UserService;

class DashboardManager
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly UserService $userService,
        private readonly MetricSnapshotService $snapshotService,
    ) {}

    /**
     * <summary>
     *  Assemble the typed DashboardStats DTO for GET /dashboard/stats.
     *  Each Stat is read from the latest org-scope MetricSnapshot row.
     * </summary>
     *
     * @return DashboardStats fragile_projects, knowledge_coverage, team_availability, absence_impact
     */
    public function getTodayStats(): DashboardStats
    {
        return new DashboardStats(
            fragileProjects: $this->projectService->getWorstFragilityStat(),
            knowledgeCoverage: $this->projectService->getKnowledgeCoverageStat(),
            teamAvailability: $this->userService->getTeamAvailabilityStat(),
            absenceImpact: $this->projectService->getAbsenceImpactStat(),
        );
    }
}
