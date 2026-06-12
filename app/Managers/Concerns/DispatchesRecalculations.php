<?php

namespace App\Managers\Concerns;

use App\Managers\CalculationRunManager;
use App\Models\Project;
use App\Models\User;

/**
 * Manager helper — funnels every recalculation trigger through
 * CalculationRunManager so debounce + run tracking apply uniformly.
 * Resolved lazily via app() to avoid circular constructor injection
 * (CalculationRunManager must stay injectable everywhere).
 */
trait DispatchesRecalculations
{
    /**
     * <summary>
     *  Queue a debounced risk recalculation for every project the user belongs to.
     *  No-op when the user is null (e.g. orphaned absence). Lazy-loads projects when missing.
     * </summary>
     *
     * @param User|null $user User whose projects need risk recalculation
     * @return void
     */
    private function dispatchProjectRecalculations(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $user->loadMissing('projects');

        foreach ($user->projects as $project) {
            $this->dispatchProjectRecalculation($project);
        }
    }

    /**
     * <summary>
     *  Queue a debounced risk recalculation for one project.
     * </summary>
     *
     * @param Project $project Target project
     * @return void
     */
    private function dispatchProjectRecalculation(Project $project): void
    {
        app(CalculationRunManager::class)->queueProjectRecalculation($project);
    }

    /**
     * <summary>
     *  Queue a debounced metrics recalculation for one user (criticality & co).
     * </summary>
     *
     * @param User $user Target user
     * @return void
     */
    private function dispatchUserRecalculation(User $user): void
    {
        app(CalculationRunManager::class)->queueUserRecalculation($user);
    }
}
