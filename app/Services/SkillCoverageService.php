<?php

namespace App\Services;

use App\Models\Project;

/**
 * STUB — calculation layer wiped. Returns hardcoded realistic values so
 * controllers/managers compile while the real engine is rebuilt from
 * settings + projects/users/skills + rules.
 *
 * TODO: replace every method with the real implementation.
 */
class SkillCoverageService
{
    public function getCoverage(Project $project): array
    {
        // TODO: real implementation — build matrix from project_skill_reqs vs assigned users.
        return $this->stubMatrix($project);
    }

    public function getCoverageAfterAbsence(Project $project, array $excludedUserIds): array
    {
        // TODO: real implementation — same as getCoverage minus excluded users.
        return $this->stubMatrix($project);
    }

    public function getRedundancy(Project $project): array
    {
        // TODO: real implementation — count of covering users per skill.
        $matrix = $this->stubMatrix($project);
        $out = [];
        foreach ($matrix as $skillId => $row) {
            $out[$skillId] = count($row['employees']);
        }
        return $out;
    }

    private function stubMatrix(Project $project): array
    {
        $project->loadMissing('skillRequirements');

        $statuses = ['safe', 'siloed', 'uncovered'];
        $result = [];
        $i = 0;

        foreach ($project->skillRequirements as $skill) {
            $status = $statuses[$i % 3];
            $count  = match ($status) {
                'safe'     => 3,
                'siloed'   => 1,
                'uncovered'=> 0,
            };

            $employees = [];
            for ($j = 0; $j < $count; $j++) {
                $employees[] = [
                    'user_id' => 1000 + $j,
                    'name'    => "Stub User {$j}",
                    'level'   => 4,
                ];
            }

            $result[$skill->id] = [
                'skill_id'       => $skill->id,
                'skill_name'     => $skill->name,
                'required_level' => $skill->pivot->required_level ?? 3,
                'employees'      => $employees,
                'status'         => $status,
            ];
            $i++;
        }

        return $result;
    }
}
