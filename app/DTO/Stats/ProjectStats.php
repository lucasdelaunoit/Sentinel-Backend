<?php

namespace App\DTO\Stats;

use App\Metrics\Stat;

/**
 * Typed DTO returned by ProjectManager::getProjectStats — wire shape for GET /projects/{project}/stats.
 */
final readonly class ProjectStats
{
    public function __construct(
        public Stat $fragility,
        public Stat $teamAvailability,
        public Stat $knowledgeCoverage,
    ) {}

    public function toArray(): array
    {
        return [
            'fragility' => $this->fragility->toArray(),
            'team_availability' => $this->teamAvailability->toArray(),
            'knowledge_coverage' => $this->knowledgeCoverage->toArray(),
        ];
    }
}
