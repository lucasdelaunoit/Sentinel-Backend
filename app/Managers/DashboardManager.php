<?php

namespace App\Managers;

use App\DTO\Stats\DashboardStats;
use App\DTO\Stats\KnowledgeCoverageBreakdown;
use App\Metrics\Calculators\AbsenceImpactCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Metrics\Calculators\KnowledgeCoverageCalculator;
use App\Metrics\Calculators\TeamAvailabilityCalculator;
use App\Services\ProjectService;
use App\Services\SkillCoverageService;
use App\Services\UserService;
use Throwable;

class DashboardManager
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly UserService $userService,
        private readonly FragilityCalculator $fragilityCalculator,
        private readonly KnowledgeCoverageCalculator $knowledgeCoverageCalculator,
        private readonly TeamAvailabilityCalculator $teamAvailabilityCalculator,
        private readonly AbsenceImpactCalculator $absenceImpactCalculator,
        private readonly SkillCoverageService $skillCoverageService,
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

    /**
     * <summary>
     *  Per-skill-category knowledge-coverage breakdown for GET /dashboard/knowledge-coverage (competency radar).
     *  Read-only — derives live from each active project's coverage matrix, writes no snapshot.
     * </summary>
     *
     * @return KnowledgeCoverageBreakdown categories[] (one per skill category) + most_fragile
     */
    public function getKnowledgeCoverage(): KnowledgeCoverageBreakdown
    {
        return $this->skillCoverageService->getKnowledgeCoverage();
    }

    /**
     * <summary>
     *  Capture the 4 org-scope dashboard-stats snapshots. Each Calculator owns its own transaction.
     *  Worst-fragility is written as part of FragilityCalculator::forOrg() — call that separately
     *  (e.g. via ProjectManager::captureProjectsStatsSnapshots) rather than duplicating it here.
     *  Not wired to a trigger yet — call from a future cron / org-recalc job.
     * </summary>
     *
     * @return void
     * @throws Throwable When any Calculator transaction fails
     */
    public function captureDashboardStatsSnapshots(): void
    {
        $this->knowledgeCoverageCalculator->forOrg();
        $this->teamAvailabilityCalculator->forOrg();
        $this->absenceImpactCalculator->forOrg();
        // DashboardWorstFragility is part of FragilityCalculator::forOrg()
    }
}
