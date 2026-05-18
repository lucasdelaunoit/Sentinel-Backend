<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;

class SkillCoverageService
{
    public function __construct(
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    public function getCoverage(Project $project): array
    {
        return $this->buildCoverage($project, []);
    }

    public function getCoverageAfterAbsence(Project $project, array $excludedUserIds): array
    {
        return $this->buildCoverage($project, $excludedUserIds);
    }

    public function getRedundancy(Project $project): array
    {
        return collect($this->getCoverage($project))
            ->mapWithKeys(fn($skill) => [$skill['skill_id'] => count($skill['employees'])])
            ->all();
    }

    private function buildCoverage(Project $project, array $excludedIds): array
    {
        // TODO: refactor coverage computation — extract "active-today absence" predicate to AbsenceService.
        $project->loadMissing([
            'skillRequirements',
            'users.skills',
            'users.absences',
        ]);

        $today        = Carbon::today();
        $siloThreshold = $this->orgSettings->getOrganizationSetting()->silo_threshold;

        $absentIds = array_unique(array_merge(
            $excludedIds,
            $project->users
                ->filter(fn(User $u) => $u->absences->contains(
                    fn($a) => Carbon::parse($a->start_date)->lte($today)
                        && Carbon::parse($a->end_date)->gte($today)
                ))
                ->pluck('id')
                ->all()
        ));

        $available = $project->users->whereNotIn('id', $absentIds)->values();

        $result = [];

        foreach ($project->skillRequirements as $skill) {
            $required = $skill->pivot->required_level;

            $covering = $available
                ->filter(function (User $u) use ($skill, $required) {
                    $match = $u->skills->firstWhere('id', $skill->id);
                    return $match && $match->pivot->level >= $required;
                })
                ->map(fn(User $u) => [
                    'user_id' => $u->id,
                    'name'    => $u->name,
                    'level'   => $u->skills->firstWhere('id', $skill->id)->pivot->level,
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
                    $count === 0              => 'uncovered',
                    $count <= $siloThreshold  => 'siloed',
                    default                   => 'safe',
                },
            ];
        }

        return $result;
    }
}
