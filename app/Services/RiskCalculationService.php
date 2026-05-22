<?php

namespace App\Services;

use App\Models\SkillCategory;
use App\Models\User;

/**
 * Residual home for metric calculations that don't yet have their own Calculator class.
 *
 * Fragility + bus factor moved to App\Metrics\Calculators\FragilityCalculator
 * and App\Metrics\Calculators\BusFactorCalculator. Remaining methods here
 * (KCI, user criticality) will follow the same extraction pattern when they
 * gain a snapshot scope.
 */
class RiskCalculationService
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    /**
     * <summary>
     *  Knowledge Coverage Index for a skill category. Percentage of category-skill-holders
     *  whose level meets the kci_min_level threshold.
     * </summary>
     *
     * @param SkillCategory $category
     * @return float 0-100
     */
    public function computeKCI(SkillCategory $category): float
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $minLevel = (int) $settings->kci_min_level;

        $skillIds = $category->skills()->pluck('skills.id')->all();
        if ($skillIds === []) return 100.0;

        $totalHolders = User::whereHas('skills', fn($q) => $q->whereIn('skills.id', $skillIds))->count();
        if ($totalHolders === 0) return 0.0;

        $proficient = User::whereHas('skills', fn($q) =>
            $q->whereIn('skills.id', $skillIds)->where('user_skills.level', '>=', $minLevel)
        )->count();

        return round(($proficient / $totalHolders) * 100, 1);
    }

    /**
     * <summary>
     *  User criticality breakdown. Counts unique skills (sole holder org-wide),
     *  silo participation across active projects, and active projects where the user
     *  pushes bus factor at or below critical_bus_factor_threshold.
     *  Score is a 0-100 composite weighted blend.
     * </summary>
     *
     * @param User $user
     * @return array{score:int,unique_skills:int,silo_count:int,bus_factor_projects:int}
     */
    public function computeUserCriticality(User $user): array
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $threshold = (int) $settings->critical_bus_factor_threshold;
        $user->loadMissing(['skills', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences']);

        $userSkillIds = $user->skills->pluck('id')->all();
        $uniqueCount = 0;
        if ($userSkillIds !== []) {
            $holderCounts = User::whereHas('skills', fn($q) => $q->whereIn('skills.id', $userSkillIds))
                ->join('user_skills', 'users.id', '=', 'user_skills.user_id')
                ->whereIn('user_skills.skill_id', $userSkillIds)
                ->selectRaw('user_skills.skill_id, count(distinct users.id) as c')
                ->groupBy('user_skills.skill_id')
                ->pluck('c', 'user_skills.skill_id');
            foreach ($userSkillIds as $sid) {
                if ((int) ($holderCounts[$sid] ?? 0) === 1) $uniqueCount++;
            }
        }

        $siloCount = 0;
        $busFactorProjects = 0;

        foreach ($user->projects as $project) {
            if ($project->archived_at !== null || $project->completed_at !== null) continue;

            $matrix = $this->coverage->getCoverage($project);

            $isInSmallestCover = false;
            $smallest = PHP_INT_MAX;
            foreach ($matrix as $row) {
                $count = count($row['employees']);
                if ($count > 0 && $count < $smallest) $smallest = $count;
            }

            foreach ($matrix as $row) {
                $uids = array_column($row['employees'], 'user_id');
                if (!in_array($user->id, $uids, true)) continue;
                if ($row['status'] === 'siloed') $siloCount++;
                if ($smallest !== PHP_INT_MAX && count($uids) === $smallest && $smallest <= $threshold) {
                    $isInSmallestCover = true;
                }
            }

            if ($isInSmallestCover) $busFactorProjects++;
        }

        $score = min(100, $uniqueCount * 25 + $siloCount * 10 + $busFactorProjects * 15);

        return [
            'score' => $score,
            'unique_skills' => $uniqueCount,
            'silo_count' => $siloCount,
            'bus_factor_projects' => $busFactorProjects,
        ];
    }
}
