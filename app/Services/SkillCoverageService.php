<?php

namespace App\Services;

use App\DTO\Stats\KnowledgeCoverageBreakdown;
use App\Models\Project;
use App\Models\Skill;
use App\Models\SkillCategory;
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
     *  $presentUserIds forces users to count as available regardless of their real horizon-absence —
     *  used to build a clean baseline isolating one person's impact (Upcoming Risk Events).
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int> $absentUserIds Virtual absence roster (simulation). Empty for live state.
     * @param array<int> $presentUserIds Users forced present, overriding their real horizon-absence.
     * @return array<int, array{skill_id:int,skill_name:string,required_level:int,employees:array<int,array{user_id:int,name:string,level:int}>,status:string}>
     */
    public function getCoverage(Project $project, array $absentUserIds = [], array $presentUserIds = []): array
    {
        $project->loadMissing(['skillRequirements', 'users.skills', 'users.absences']);

        $settings      = $this->orgSettings->getOrganizationSetting();
        $siloThreshold = (int) $settings->silo_threshold;
        $horizonDays   = (int) $settings->absence_horizon_days;

        $today      = Carbon::today();
        $horizonEnd = (clone $today)->addDays($horizonDays);

        $availableUsers = $project->users->reject(function ($user) use ($absentUserIds, $presentUserIds, $today, $horizonEnd) {
            if (in_array($user->id, $presentUserIds, true)) {
                return false;
            }
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
     *  Org-wide knowledge-coverage broken down per skill category — feeds the competency radar.
     *  Every SkillCategory is a radar axis (stable even with zero requirements). Walks every active
     *  project's coverage matrix (reuses getCoverage — the single source of truth) and buckets each
     *  required skill into its category as safe / siloed / uncovered. coverage_pct = safe / total per
     *  category (100 when a category has no requirements). most_fragile is the lowest-coverage
     *  category that actually has requirements. Read-only — writes nothing.
     * </summary>
     *
     * @return KnowledgeCoverageBreakdown categories[] (one per skill category) + most_fragile
     */
    public function getKnowledgeCoverage(): KnowledgeCoverageBreakdown
    {
        $categories = SkillCategory::query()->orderBy('name')->get(['id', 'name']);
        $skillCategory = Skill::query()->pluck('skill_category_id', 'id');

        $acc = [];
        foreach ($categories as $category) {
            $acc[$category->id] = [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'safe' => 0,
                'siloed' => 0,
                'uncovered' => 0,
                'siloed_skills' => [],
                'uncovered_skills' => [],
            ];
        }

        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        foreach ($projects as $project) {
            foreach ($this->getCoverage($project) as $row) {
                $categoryId = $skillCategory[$row['skill_id']] ?? null;
                if ($categoryId === null || !isset($acc[$categoryId])) {
                    continue;
                }

                $acc[$categoryId][$row['status']]++;
                if ($row['status'] === 'siloed') {
                    $acc[$categoryId]['siloed_skills'][$row['skill_id']] = $row['skill_name'];
                } elseif ($row['status'] === 'uncovered') {
                    $acc[$categoryId]['uncovered_skills'][$row['skill_id']] = $row['skill_name'];
                }
            }
        }

        $result = [];
        $mostFragile = null;
        $worstPct = null;
        foreach ($acc as $bucket) {
            $total = $bucket['safe'] + $bucket['siloed'] + $bucket['uncovered'];
            $pct = $total === 0 ? 100 : (int) round(($bucket['safe'] / $total) * 100);

            $result[] = [
                'category_id' => $bucket['category_id'],
                'category_name' => $bucket['category_name'],
                'coverage_pct' => $pct,
                'safe' => $bucket['safe'],
                'siloed' => $bucket['siloed'],
                'uncovered' => $bucket['uncovered'],
                'siloed_skills' => array_values($bucket['siloed_skills']),
                'uncovered_skills' => array_values($bucket['uncovered_skills']),
            ];

            if ($total > 0 && ($worstPct === null || $pct < $worstPct)) {
                $worstPct = $pct;
                $mostFragile = $bucket['category_name'];
            }
        }

        return new KnowledgeCoverageBreakdown($result, $mostFragile);
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
