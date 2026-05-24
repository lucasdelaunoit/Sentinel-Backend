<?php

namespace App\DTO\Stats;

use App\Metrics\Stat;

/**
 * Typed DTO returned by ProjectManager::getProjectsStats — wire shape for GET /projects/stats.
 */
final readonly class ProjectsStats
{
    public function __construct(
        public Stat $total,
        public Stat $avgFragility,
        public Stat $fragileCount,
        public Stat $deadlinePressure,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total->toArray(),
            'avg_fragility' => $this->avgFragility->toArray(),
            'fragile_count' => $this->fragileCount->toArray(),
            'deadline_pressure' => $this->deadlinePressure->toArray(),
        ];
    }
}
