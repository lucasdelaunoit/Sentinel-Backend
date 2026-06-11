<?php

namespace App\Managers\Concerns;

use App\Jobs\RecalculateProjectRiskJob;
use App\Models\User;

trait DispatchesProjectRecalculations
{
    /**
     * <summary>
     *  Dispatch RecalculateProjectRiskJob for every project the user belongs to.
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
            RecalculateProjectRiskJob::dispatch($project);
        }
    }
}
