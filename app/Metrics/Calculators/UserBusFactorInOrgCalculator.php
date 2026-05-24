<?php

namespace App\Metrics\Calculators;

use App\Models\User;

/**
 * Count of active projects where the user is on the team AND the project's
 * bus_factor is <= 2 — i.e. projects where losing this user threatens coverage.
 *
 * Uses BusFactorCalculator to compute live since projects.bus_factor is no
 * longer cached.
 */
class UserBusFactorInOrgCalculator
{
    public function __construct(
        private readonly BusFactorCalculator $busFactor,
    ) {}

    /**
     * <summary>
     *  Count active projects exposing the user to single-point-of-failure risk (bus_factor &lt;= 2).
     * </summary>
     *
     * @param User $user Target user
     * @return int
     */
    public function calculate(User $user): int
    {
        $user->loadMissing('projects');
        $now = now();

        return $user->projects
            ->filter(fn($p) => $p->started_at !== null
                && $p->started_at <= $now
                && $p->paused_at === null
                && $p->completed_at === null
                && $p->archived_at === null
                && $this->busFactor->calculate($p) <= 2
            )
            ->count();
    }
}
