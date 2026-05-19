<?php

namespace App\Services;

use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Models\SkillCategory;
use App\Models\User;

class RiskCalculationService
{
    public function __construct(
        private readonly SkillCoverageService       $coverage,
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    /**
     * <summary>
     *  Bus factor of a project. Min number of users whose absence breaks at least one
     *  required skill. Equivalent to the smallest covering set across required skills.
     *  Returns 0 when no skill is currently covered (project already broken).
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds Virtual absence roster (simulation)
     * @return int
     */
    public function computeBusFactor(Project $project, array $absentUserIds = []): int
    {
        $matrix = $this->coverage->getCoverage($project, $absentUserIds);

        $coveredCounts = [];
        foreach ($matrix as $row) {
            $c = count($row['employees']);
            if ($c > 0) $coveredCounts[] = $c;
        }

        if ($coveredCounts === []) return 0;
        return min($coveredCounts);
    }

    /**
     * <summary>
     *  Weighted fragility score (0-100). Blends bus risk, uncovered ratio, silo ratio,
     *  absence impact (LAYER 1+2 per spec). Applies fragility_tolerance multiplier and
     *  adds rule_penalty derived from enabled rule violations affecting the project.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return float
     */
    public function computeFragilityRaw(Project $project, array $absentUserIds = []): float
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $matrix   = $this->coverage->getCoverage($project, $absentUserIds);
        $total    = count($matrix);

        if ($total === 0) return 0.0;

        $uncovered = 0;
        $siloed    = 0;
        foreach ($matrix as $row) {
            if ($row['status'] === 'uncovered') $uncovered++;
            elseif ($row['status'] === 'siloed') $siloed++;
        }
        $uncoveredRatio = $uncovered / $total;
        $siloRatio      = $siloed / $total;

        $absenceImpact = $this->computeAbsenceImpact($project, $matrix, $absentUserIds, $settings);

        $busFactor = $this->computeBusFactor($project, $absentUserIds);
        $busRisk   = $busFactor >= 5 ? 0 : max(0, 100 - $busFactor * 20);

        $wBus  = (int) $settings->fragility_weight_bus_factor;
        $wUnc  = (int) $settings->fragility_weight_uncovered_skills;
        $wSilo = (int) $settings->fragility_weight_silos;
        $wAbs  = (int) $settings->fragility_weight_absence_impact;
        $sumW  = max(1, $wBus + $wUnc + $wSilo + $wAbs);

        $fragility = (
            $busRisk              * $wBus  +
            $uncoveredRatio * 100 * $wUnc  +
            $siloRatio      * 100 * $wSilo +
            $absenceImpact  * 100 * $wAbs
        ) / $sumW;

        $tolerance = match ($settings->fragility_tolerance) {
            'conservative' => 1.2,
            'aggressive'   => 0.8,
            default        => 1.0,
        };

        $fragility = min(100.0, $fragility * $tolerance);
        $fragility += $this->computeRulePenalty($project, $settings);

        return max(0.0, min(100.0, $fragility));
    }

    /**
     * <summary>
     *  Trajectory score (0-100). Blends inverted fragility with time-based progress.
     *  trajectory = (100 - fragility) * w + progress * (1 - w), w = trajectory_fragility_weight/100.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return float
     */
    public function computeTrajectoryRaw(Project $project, array $absentUserIds = []): float
    {
        $settings  = $this->orgSettings->getOrganizationSetting();
        $fragility = $this->computeFragilityRaw($project, $absentUserIds);
        $progress  = (float) $project->progress;
        $w         = (int) $settings->trajectory_fragility_weight;
        $share     = $w / 100;

        $traj = (100 - $fragility) * $share + $progress * (1 - $share);
        return max(0.0, min(100.0, $traj));
    }

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
        $settings  = $this->orgSettings->getOrganizationSetting();
        $threshold = (int) $settings->critical_bus_factor_threshold;
        $user->loadMissing(['skills', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences']);

        $userSkillIds = $user->skills->pluck('id')->all();
        $uniqueCount  = 0;
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

        $siloCount         = 0;
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
            'score'               => $score,
            'unique_skills'       => $uniqueCount,
            'silo_count'          => $siloCount,
            'bus_factor_projects' => $busFactorProjects,
        ];
    }

    public static function fragilityTier(float|int $raw): string
    {
        return match (true) {
            $raw <= 20 => 'solid',
            $raw <= 40 => 'stable',
            $raw <= 60 => 'stretched',
            $raw <= 80 => 'fragile',
            default    => 'critical',
        };
    }

    public static function trajectoryTier(float|int $raw): string
    {
        return match (true) {
            $raw <= 20 => 'off_course',
            $raw <= 40 => 'drifting',
            $raw <= 60 => 'wobbling',
            $raw <= 80 => 'on_track',
            default    => 'cruising',
        };
    }

    /**
     * <summary>
     *  Map a fragility raw score to a UI severity bucket (ok / warning / critical).
     *  Higher fragility = worse, so high raw -&gt; critical.
     * </summary>
     */
    public static function fragilitySeverity(float|int $raw): string
    {
        return match (true) {
            $raw <= 40 => 'ok',
            $raw <= 60 => 'warning',
            default    => 'critical',
        };
    }

    /**
     * <summary>
     *  Map a trajectory raw score to a UI severity bucket (ok / warning / critical).
     *  Higher trajectory = better, so low raw -&gt; critical.
     * </summary>
     */
    public static function trajectorySeverity(float|int $raw): string
    {
        return match (true) {
            $raw <= 40 => 'critical',
            $raw <= 60 => 'warning',
            default    => 'ok',
        };
    }

    /**
     * <summary>
     *  Map a user criticality score (0-100) to a UI severity bucket.
     *  Higher score = more critical, so high score -&gt; critical severity.
     * </summary>
     */
    public static function criticalitySeverity(int $score): string
    {
        return match (true) {
            $score < 30 => 'ok',
            $score < 60 => 'warning',
            default     => 'critical',
        };
    }

    /**
     * <summary>
     *  Newly-uncovered ratio when horizon absences are projected on top of the live state.
     *  Absences already inside $absentUserIds are part of the baseline, not "added".
     * </summary>
     */
    private function computeAbsenceImpact(Project $project, array $baselineMatrix, array $absentUserIds, OrganizationSetting $settings): float
    {
        $total = count($baselineMatrix);
        if ($total === 0) return 0.0;

        $horizonAbsent = $this->getHorizonAbsentUserIds($project, (int) $settings->absence_horizon_days);
        $merged        = array_values(array_unique(array_merge($absentUserIds, $horizonAbsent)));

        if ($merged === $absentUserIds) return 0.0;

        $with = $this->coverage->getCoverage($project, $merged);
        $newlyUncovered = 0;
        foreach ($with as $sid => $row) {
            if ($row['status'] === 'uncovered' && ($baselineMatrix[$sid]['status'] ?? 'uncovered') !== 'uncovered') {
                $newlyUncovered++;
            }
        }
        return $newlyUncovered / $total;
    }

    /**
     * <summary>
     *  User ids assigned to the project who have any absence overlapping the horizon window.
     * </summary>
     *
     * @return array<int>
     */
    private function getHorizonAbsentUserIds(Project $project, int $horizonDays): array
    {
        $today      = now()->toDateString();
        $horizonEnd = now()->addDays($horizonDays)->toDateString();

        return $project->users()
            ->whereHas('absences', fn($q) => $q
                ->whereDate('start_date', '<=', $horizonEnd)
                ->whereDate('end_date',   '>=', $today)
            )
            ->pluck('users.id')
            ->all();
    }

    /**
     * <summary>
     *  Additive fragility penalty from rule violations affecting this project.
     *  (violations_for_project / project_rules_count) * rule_violation_penalty.
     *  Resolves RuleEvaluator lazily via container to avoid circular constructor dependency.
     * </summary>
     */
    private function computeRulePenalty(Project $project, OrganizationSetting $settings): float
    {
        $ruleService = app(RuleService::class);
        $rules       = $ruleService->getEnabledRules();
        if ($rules->isEmpty()) return 0.0;

        $applicable = $rules->filter(fn($r) => $this->ruleAppliesTo($r, $project));
        if ($applicable->isEmpty()) return 0.0;

        $evaluator    = app(RuleEvaluator::class);
        $allViolations = $evaluator->evaluateOrganization();

        $hit = 0;
        foreach ($allViolations as $v) {
            if (($v['subject_type'] === 'project' && (int) $v['subject_id'] === $project->id)) {
                $hit++;
            } elseif ($v['subject_type'] === 'organization') {
                $ruleId = (int) $v['rule_id'];
                $rule   = $applicable->firstWhere('id', $ruleId);
                if ($rule) $hit++;
            }
        }

        return ($hit / $applicable->count()) * (int) $settings->rule_violation_penalty;
    }

    private function ruleAppliesTo($rule, Project $project): bool
    {
        return match ($rule->scope_type) {
            'project'    => (int) $rule->scope_id === (int) $project->id,
            'department' => $project->users()->where('department_id', $rule->scope_id)->exists(),
            default      => true,
        };
    }
}
