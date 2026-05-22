<?php

namespace App\DTO\Stats;

use App\Metrics\Stat;

/**
 * Typed DTO returned by DashboardManager::getTodayStats — wire shape for GET /dashboard/stats.
 */
final readonly class DashboardStats
{
    public function __construct(
        public Stat $fragileProjects,
        public Stat $knowledgeCoverage,
        public Stat $teamAvailability,
        public Stat $absenceImpact,
    ) {}

    public function toArray(): array
    {
        return [
            'fragile_projects' => $this->fragileProjects->toArray(),
            'knowledge_coverage' => $this->knowledgeCoverage->toArray(),
            'team_availability' => $this->teamAvailability->toArray(),
            'absence_impact' => $this->absenceImpact->toArray(),
        ];
    }
}
