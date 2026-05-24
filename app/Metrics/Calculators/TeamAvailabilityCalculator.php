<?php

namespace App\Metrics\Calculators;

use App\Models\Project;

/**
 * Raw team-availability score (0-100) for a project.
 *
 * Score = (team_size - absent_count) / team_size * 100.
 * Absent = currently on absence today OR in the virtual roster.
 *
 * Accepts optional $absentUserIds so the same code path serves the live state
 * AND simulation runs (virtual absence roster).
 */
class TeamAvailabilityCalculator
{
    /**
     * <summary>
     *  Compute the raw team-availability score (float 0-100) for a project.
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int> $absentUserIds Virtual absence roster (simulation). Empty for live state.
     * @return float
     */
    public function calculate(Project $project, array $absentUserIds = []): float
    {
        $project->loadMissing('users.absences');
        $total = $project->users->count();

        if ($total === 0) return 100.0;

        $today = now()->toDateString();
        $absent = $project->users->filter(function ($u) use ($today, $absentUserIds) {
            if (in_array($u->id, $absentUserIds, true)) return true;
            return $u->absences->contains(fn($a) => $a->start_date <= $today && $a->end_date >= $today);
        })->count();

        return (($total - $absent) / $total) * 100;
    }
}
