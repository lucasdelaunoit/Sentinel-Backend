<?php

namespace App\DTO\Stats;

use App\Metrics\Stat;

/**
 * Typed DTO returned by UserManager::getUserStats — wire shape for GET /users/{user}/stats.
 */
final readonly class UserStats
{
    public function __construct(
        public Stat $criticality,
        public Stat $busFactorInOrg,
        public Stat $skills,
        public Stat $activeProjects,
    ) {}

    public function toArray(): array
    {
        return [
            'criticality' => $this->criticality->toArray(),
            'bus_factor_in_org' => $this->busFactorInOrg->toArray(),
            'skills' => $this->skills->toArray(),
            'active_projects' => $this->activeProjects->toArray(),
        ];
    }
}
