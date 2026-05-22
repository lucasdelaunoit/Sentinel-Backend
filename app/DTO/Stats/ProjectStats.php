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
        public Stat $busFactor,
        public Stat $team,
    ) {}

    public function toArray(): array
    {
        return [
            'fragility' => $this->fragility->toArray(),
            'bus_factor' => $this->busFactor->toArray(),
            'team' => $this->team->toArray(),
        ];
    }
}
