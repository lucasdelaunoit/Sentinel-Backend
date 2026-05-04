<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Project;
use Carbon\Carbon;

class SkillCoverageService
{
    public function getCoverage(Project $project): array
    {
        return $this->buildCoverage($project, []);
    }

    public function getCoverageAfterAbsence(Project $project, array $excludedEmployeeIds): array
    {
        return $this->buildCoverage($project, $excludedEmployeeIds);
    }

    public function getRedundancy(Project $project): array
    {
        return collect($this->getCoverage($project))
            ->mapWithKeys(fn($skill) => [$skill['skill_id'] => count($skill['employees'])])
            ->all();
    }

    private function buildCoverage(Project $project, array $excludedIds): array
    {
        $project->loadMissing([
            'skillRequirements',
            'employees.skills',
            'employees.leaves',
        ]);

        $today = Carbon::today();

        $absentIds = array_unique(array_merge(
            $excludedIds,
            $project->employees
                ->filter(fn(Employee $e) => $e->leaves->contains(
                    fn($l) => Carbon::parse($l->start_date)->lte($today)
                        && Carbon::parse($l->end_date)->gte($today)
                ))
                ->pluck('id')
                ->all()
        ));

        $available = $project->employees->whereNotIn('id', $absentIds)->values();

        $result = [];

        foreach ($project->skillRequirements as $skill) {
            $required = $skill->pivot->required_level;

            $covering = $available
                ->filter(function (Employee $e) use ($skill, $required) {
                    $match = $e->skills->firstWhere('id', $skill->id);
                    return $match && $match->pivot->level >= $required;
                })
                ->map(fn(Employee $e) => [
                    'employee_id' => $e->id,
                    'name'        => $e->name,
                    'level'       => $e->skills->firstWhere('id', $skill->id)->pivot->level,
                ])
                ->values()
                ->all();

            $count = count($covering);

            $result[$skill->id] = [
                'skill_id'       => $skill->id,
                'skill_name'     => $skill->name,
                'required_level' => $required,
                'employees'      => $covering,
                'status'         => match (true) {
                    $count === 0 => 'uncovered',
                    $count === 1 => 'siloed',
                    default      => 'safe',
                },
            ];
        }

        return $result;
    }
}
