<?php

namespace App\Metrics\Calculators;

use App\Models\User;
use App\Services\RiskCalculationService;

/**
 * Raw criticality score (0-100) for a single user.
 *
 * Thin wrapper around RiskCalculationService::computeUserCriticality — exposes
 * only the score so callers (recalc job, snapshot writer) bind to the
 * Calculator contract.
 */
class UserCriticalityCalculator
{
    public function __construct(
        private readonly RiskCalculationService $risk,
    ) {}

    /**
     * <summary>
     *  Compute the raw criticality score (int 0-100) for a user.
     * </summary>
     *
     * @param User $user Target user
     * @return int
     */
    public function calculate(User $user): int
    {
        return (int) $this->risk->computeUserCriticality($user)['score'];
    }
}
