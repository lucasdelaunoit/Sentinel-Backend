<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Simulation;

/**
 * STUB — calculation layer wiped. Returns a hardcoded realistic simulation
 * payload so SimulationManager keeps working while the real engine is rebuilt.
 *
 * TODO: replace with real implementation that reuses the new coverage + scoring services.
 */
class SimulationService
{
    public function run(Simulation $simulation): array
    {
        $simulation->loadMissing(['project', 'absentUsers']);

        return $this->computeImpact(
            $simulation->project,
            $simulation->absentUsers->pluck('id')->all(),
        );
    }

    public function computeImpact(Project $project, array $absentUserIds): array
    {
        // TODO: real implementation — diff baseline vs absence-injected matrix, recompute scores.
        return [
            'project_id'       => $project->id,
            'absent_users'     => $absentUserIds,
            'original_metrics' => [
                'bus_factor'     => 3,
                'fragility_raw'  => 42.5,
                'trajectory_raw' => 67.0,
            ],
            'simulated_metrics' => [
                'bus_factor'     => 1,
                'fragility_raw'  => 71.0,
                'trajectory_raw' => 38.0,
            ],
            'coverage_diff' => [
                101 => ['before' => 'safe',   'after' => 'siloed',    'skill_name' => 'Stub Skill A'],
                102 => ['before' => 'siloed', 'after' => 'uncovered', 'skill_name' => 'Stub Skill B'],
            ],
            'newly_uncovered_count' => 1,
            'newly_siloed_count'    => 1,
        ];
    }
}
