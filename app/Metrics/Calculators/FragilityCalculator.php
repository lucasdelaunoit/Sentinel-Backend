<?php

namespace App\Metrics\Calculators;

use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Services\OrganizationSettingService;
use App\Services\RuleEvaluator;
use App\Services\RuleService;
use App\Services\SkillCoverageService;

/**
 * Raw fragility score (0-100) for a project. Blends bus risk, uncovered ratio,
 * silo ratio, absence impact (LAYER 1+2 per spec). Applies fragility_tolerance
 * multiplier and adds rule_penalty from violations affecting the project.
 *
 * Accepts optional $absentUserIds so the same code path serves the live state
 * AND simulation runs (virtual absence roster).
 */
class FragilityCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly OrganizationSettingService $orgSettings,
        private readonly BusFactorCalculator $busFactor,
    ) {}

    /**
     * <summary>
     *  Compute the raw fragility score (float 0-100) for a project.
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int> $absentUserIds Virtual absence roster (simulation). Empty for live state.
     * @return float
     */
    public function calculate(Project $project, array $absentUserIds = []): float
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $matrix = $this->coverage->getCoverage($project, $absentUserIds);
        $total = count($matrix);

        if ($total === 0) return 0.0;

        $uncovered = 0;
        $siloed = 0;
        foreach ($matrix as $row) {
            if ($row['status'] === 'uncovered') $uncovered++;
            elseif ($row['status'] === 'siloed') $siloed++;
        }
        $uncoveredRatio = $uncovered / $total;
        $siloRatio = $siloed / $total;

        $absenceImpact = $this->computeAbsenceImpact($project, $matrix, $absentUserIds, $settings);

        $bf = $this->busFactor->calculate($project, $absentUserIds);
        $busRisk = $bf >= 5 ? 0 : max(0, 100 - $bf * 20);

        $wBus = (int) $settings->fragility_weight_bus_factor;
        $wUnc = (int) $settings->fragility_weight_uncovered_skills;
        $wSilo = (int) $settings->fragility_weight_silos;
        $wAbs = (int) $settings->fragility_weight_absence_impact;
        $sumW = max(1, $wBus + $wUnc + $wSilo + $wAbs);

        $fragility = (
            $busRisk * $wBus +
            $uncoveredRatio * 100 * $wUnc +
            $siloRatio * 100 * $wSilo +
            $absenceImpact * 100 * $wAbs
        ) / $sumW;

        $tolerance = match ($settings->fragility_tolerance) {
            'conservative' => 1.2,
            'aggressive' => 0.8,
            default => 1.0,
        };

        $fragility = min(100.0, $fragility * $tolerance);
        $fragility += $this->computeRulePenalty($project, $settings);

        return max(0.0, min(100.0, $fragility));
    }

    /**
     * Newly-uncovered ratio when horizon absences are projected on top of the live state.
     * Absences already inside $absentUserIds are part of the baseline, not "added".
     */
    private function computeAbsenceImpact(Project $project, array $baselineMatrix, array $absentUserIds, OrganizationSetting $settings): float
    {
        $total = count($baselineMatrix);
        if ($total === 0) return 0.0;

        $horizonAbsent = $this->getHorizonAbsentUserIds($project, (int) $settings->absence_horizon_days);
        $merged = array_values(array_unique(array_merge($absentUserIds, $horizonAbsent)));

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
     * @return array<int>
     */
    private function getHorizonAbsentUserIds(Project $project, int $horizonDays): array
    {
        $today = now()->toDateString();
        $horizonEnd = now()->addDays($horizonDays)->toDateString();

        return $project->users()
            ->whereHas('absences', fn($q) => $q
                ->whereDate('start_date', '<=', $horizonEnd)
                ->whereDate('end_date', '>=', $today)
            )
            ->pluck('users.id')
            ->all();
    }

    /**
     * Additive fragility penalty from rule violations affecting this project.
     * Resolves RuleEvaluator lazily to avoid circular dependency at construction.
     */
    private function computeRulePenalty(Project $project, OrganizationSetting $settings): float
    {
        $ruleService = app(RuleService::class);
        $rules = $ruleService->getEnabledRules();
        if ($rules->isEmpty()) return 0.0;

        $applicable = $rules->filter(fn($r) => $this->ruleAppliesTo($r, $project));
        if ($applicable->isEmpty()) return 0.0;

        $evaluator = app(RuleEvaluator::class);
        $allViolations = $evaluator->evaluateOrganization();

        $hit = 0;
        foreach ($allViolations as $v) {
            if ($v['subject_type'] === 'project' && (int) $v['subject_id'] === $project->id) {
                $hit++;
            } elseif ($v['subject_type'] === 'organization') {
                $ruleId = (int) $v['rule_id'];
                $rule = $applicable->firstWhere('id', $ruleId);
                if ($rule) $hit++;
            }
        }

        return ($hit / $applicable->count()) * (int) $settings->rule_violation_penalty;
    }

    private function ruleAppliesTo($rule, Project $project): bool
    {
        return match ($rule->scope_type) {
            'project' => (int) $rule->scope_id === (int) $project->id,
            'department' => $project->users()->where('department_id', $rule->scope_id)->exists(),
            default => true,
        };
    }
}
