<?php

namespace App\Services;

use App\Models\Project;
use Carbon\Carbon;

class SkillCoverageService
{
    public function __construct(
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    /**
     * <summary>
     *  Build the per-project skill coverage matrix (LAYER 0).
     *  For each required skill, lists assigned users whose skill level >= required_level
     *  and who are not absent within absence_horizon_days. Status is uncovered (0),
     *  siloed (<= silo_threshold) or safe.
     *  $absentUserIds adds a virtual absence roster on top of real absences — used by simulations.
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int> $absentUserIds Virtual absence roster (simulation). Empty for live state.
     * @return array<int, array{skill_id:int,skill_name:string,required_level:int,employees:array<int,array{user_id:int,name:string,level:int}>,status:string}>
     */
    public function getCoverage(Project $project, array $absentUserIds = []): array
    {
        $project->loadMissing(['skillRequirements', 'users.skills', 'users.absences']);

        $settings      = $this->orgSettings->getOrganizationSetting();
        $siloThreshold = (int) $settings->silo_threshold;
        $horizonDays   = (int) $settings->absence_horizon_days;

        $today      = Carbon::today();
        $horizonEnd = (clone $today)->addDays($horizonDays);

        $availableUsers = $project->users->reject(function ($user) use ($absentUserIds, $today, $horizonEnd) {
            if (in_array($user->id, $absentUserIds, true)) {
                return true;
            }
            return $user->absences->contains(function ($a) use ($today, $horizonEnd) {
                $start = Carbon::parse($a->start_date);
                $end   = Carbon::parse($a->end_date);
                return $start->lte($horizonEnd) && $end->gte($today);
            });
        });

        $matrix = [];
        foreach ($project->skillRequirements as $skill) {
            $required = (int) ($skill->pivot->required_level ?? 1);
            $covering = [];

            foreach ($availableUsers as $user) {
                $userSkill = $user->skills->firstWhere('id', $skill->id);
                if ($userSkill && (int) $userSkill->pivot->level >= $required) {
                    $covering[] = [
                        'user_id' => $user->id,
                        'name'    => trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? '')) ?: $user->email,
                        'level'   => (int) $userSkill->pivot->level,
                    ];
                }
            }

            $count  = count($covering);
            $status = $count === 0
                ? 'uncovered'
                : ($count <= $siloThreshold ? 'siloed' : 'safe');

            $matrix[$skill->id] = [
                'skill_id'       => $skill->id,
                'skill_name'     => $skill->name,
                'required_level' => $required,
                'employees'      => $covering,
                'status'         => $status,
            ];
        }

        return $matrix;
    }

    /**
     * <summary>
     *  Backward-compatible shim — delegates to getCoverage with the excluded user roster.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $excludedUserIds
     * @return array
     */
    public function getCoverageAfterAbsence(Project $project, array $excludedUserIds): array
    {
        return $this->getCoverage($project, $excludedUserIds);
    }

    /**
     * <summary>
     *  Per-skill redundancy: count of covering users from the live matrix.
     * </summary>
     *
     * @param Project $project
     * @return array<int,int> skill_id => covering user count
     */
    public function getRedundancy(Project $project): array
    {
        $out = [];
        foreach ($this->getCoverage($project) as $skillId => $row) {
            $out[$skillId] = count($row['employees']);
        }
        return $out;
    }
}
