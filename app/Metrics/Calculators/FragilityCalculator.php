<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Scales\FragilityScale;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Severity;
use App\Metrics\Stat;
use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Services\OrganizationSettingService;
use App\Services\RuleEvaluator;
use App\Services\RuleService;
use App\Services\SkillCoverageService;
use Illuminate\Support\Collection;

/**
 * Fragility metric — composite 0-100 score blending bus risk, uncovered ratio, silo ratio,
 * absence impact, and rule violations. LAYER 1+2 per spec.
 *
 * Scopes:
 *  - forProject — per-project fragility. Persists projects.fragility_raw + snapshot.
 *  - forOrg     — writes 3 org snapshots in one transaction (avg fragility, fragile count, worst fragility).
 */
class FragilityCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly OrganizationSettingService $orgSettings,
        private readonly BusFactorCalculator $busFactor,
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  CORE math. Composite blend with tolerance multiplier and additive rule penalty. Clamped 0-100.
     * </summary>
     *
     * @param int $busRisk 0-100, higher = worse
     * @param float $uncoveredRatio 0-1
     * @param float $siloRatio 0-1
     * @param float $absenceImpact 0-1
     * @param OrganizationSetting $settings
     * @param float $rulePenalty Additive after tolerance
     * @return float 0-100
     */
    private function calculateCore(
        int $busRisk,
        float $uncoveredRatio,
        float $siloRatio,
        float $absenceImpact,
        OrganizationSetting $settings,
        float $rulePenalty,
    ): float {
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
        $fragility += $rulePenalty;

        return max(0.0, min(100.0, $fragility));
    }

    /**
     * <summary>
     *  Pure raw fragility score for a project. No DB writes. Reads the live coverage matrix and
     *  composes sibling Calculators (BusFactorCalculator) for inputs.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @param array<int> $presentUserIds Users forced present, overriding their real horizon-absence (clean baseline isolation)
     * @return float
     */
    public function computeRawForProject(Project $project, array $absentUserIds = [], array $presentUserIds = []): float
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $matrix = $this->coverage->getCoverage($project, $absentUserIds, $presentUserIds);
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
        $absenceImpact = $this->computeAbsenceImpactRatio($project, $matrix, $absentUserIds, $settings, $presentUserIds);

        $bf = $this->busFactor->computeRawForProject($project, $absentUserIds, $presentUserIds);
        $busRisk = $bf >= 5 ? 0 : max(0, 100 - $bf * 20);

        $rulePenalty = $this->computeRulePenalty($project, $settings);

        return $this->calculateCore($busRisk, $uncoveredRatio, $siloRatio, $absenceImpact, $settings, $rulePenalty);
    }

    /**
     * <summary>
     *  Persist project fragility — updates projects.fragility_raw + appends snapshot.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forProject(Project $project, array $absentUserIds = []): MetricSnapshot
    {
        $raw = (int) round($this->computeRawForProject($project, $absentUserIds));
        $stat = Stat::fromScale(FragilityScale::fromRaw($raw), $raw, "Score: {$raw}/100");

        return $this->metricsManager->persistProjectMetric($project, 'fragility_raw', MetricKey::Fragility, $stat);
    }

    /**
     * <summary>
     *  Persist 3 org-scope fragility snapshots in a single transaction:
     *  ProjectsAvgFragility, ProjectsFragileCount, DashboardWorstFragility.
     *  Reads projects.fragility_raw cached column — assumes per-project recalc ran first.
     * </summary>
     *
     * @return Collection<int, MetricSnapshot>
     * @throws \Throwable
     */
    public function forOrg(): Collection
    {
        $scores = Project::query()->whereNull('archived_at')->pluck('fragility_raw');
        $activeScores = Project::active()->pluck('fragility_raw');

        $avg = (int) round($scores->avg() ?? 0);
        $fragile = $scores->filter(fn($v) => $v > 60)->count();
        $worst = (int) ($activeScores->max() ?? 0);

        $worstFragile = $activeScores->filter(fn($v) => $v > 60)->count();
        $worstStretched = $activeScores->filter(fn($v) => $v > 40 && $v <= 60)->count();
        $worstParts = [];
        if ($worstFragile > 0) $worstParts[] = "{$worstFragile} fragile";
        if ($worstStretched > 0) $worstParts[] = "{$worstStretched} stretched";
        $worstInsight = empty($worstParts) ? 'All projects healthy' : implode(' · ', $worstParts);

        return $this->metricsManager->persistOrgMetrics([
            [MetricKey::ProjectsAvgFragility, Stat::fromScale(FragilityScale::fromRaw($avg), $avg, "Score: {$avg}/100")],
            [MetricKey::ProjectsFragileCount, new Stat(
                value: $fragile === 0 ? 'Healthy' : (string) $fragile,
                valueRaw: $fragile,
                severity: $fragile > 0 ? Severity::CRITICAL : Severity::OK,
                insight: $fragile > 0 ? 'Fragility > 60' : null,
            )],
            [MetricKey::DashboardWorstFragility, Stat::fromScale(FragilityScale::fromRaw($worst), $worst, $worstInsight)],
        ]);
    }

    /**
     * Newly-uncovered ratio when horizon absences are projected on top of the live state.
     * $presentUserIds keeps forced-present users available in the projection (clean baseline isolation).
     */
    private function computeAbsenceImpactRatio(Project $project, array $baselineMatrix, array $absentUserIds, OrganizationSetting $settings, array $presentUserIds = []): float
    {
        $total = count($baselineMatrix);
        if ($total === 0) return 0.0;

        $horizonAbsent = $this->getHorizonAbsentUserIds($project, (int) $settings->absence_horizon_days);
        $merged = array_values(array_unique(array_merge($absentUserIds, $horizonAbsent)));

        if ($merged === $absentUserIds) return 0.0;

        $with = $this->coverage->getCoverage($project, $merged, $presentUserIds);
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
