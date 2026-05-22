<?php

namespace App\DTO\Stats;

use App\Metrics\Stat;

/**
 * Typed DTO returned by UserManager::getUsersStats — wire shape for GET /users/stats.
 */
final readonly class UsersStats
{
    public function __construct(
        public Stat $total,
        public Stat $available,
        public Stat $criticalUsers,
        public Stat $uniqueSkillHolders,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total->toArray(),
            'available' => $this->available->toArray(),
            'critical_users' => $this->criticalUsers->toArray(),
            'unique_skill_holders' => $this->uniqueSkillHolders->toArray(),
        ];
    }
}
