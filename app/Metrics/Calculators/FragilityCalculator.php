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
use App\Services\AbsenceService;
use App\Services\OrganizationSettingService;
use App\Services\SkillCoverageService;
use Illuminate\Support\Collection;

/**
 * Fragility metric — composite 0-100 score (higher = worse). Spec LAYER 1+2.
 *
 * How this calculator is built — read top to bottom:
 *
 *   LAYER 2 · CORE   calculateCore()            pure math: 4 scalars → score. No data access.
 *   LAYER 1 · RAW    computeRawForProject()     coverage matrix → the 4 scalars → CORE. No DB writes.
 *                    computeAbsenceImpactRatio() private helper: builds the absence_impact scalar.
 *   SCOPE · PERSIST  forProject()               project scope: compute raw, write column + snapshot.
 *                    forOrg()                    org scope: aggregate cached fragility_raw (no recompute).
 *
 * Rule of thumb: RAW methods are pure (reused by simulation, never persist); FOR methods persist.
 * Raw DB queries live in injected Services (coverage, absences) — this class only does metric math.
 */
class FragilityCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly OrganizationSettingService $orgSettings,
        private readonly BusFactorCalculator $busFactor,
        private readonly AbsenceService $absences,
        private readonly MetricsManager $metricsManager,
    ) {}

    /* ════════════════ LAYER 2 · CORE — pure math ════════════════ */

    /**
     * <summary>
     *  CORE math. Composite blend with tolerance multiplier. Clamped 0-100.
     * </summary>
     *
     * @param int $busRisk 0-100, higher = worse
     * @param float $uncoveredRatio 0-1
     * @param float $siloRatio 0-1
     * @param float $absenceImpact 0-1
     * @param OrganizationSetting $settings
     * @return float 0-100
     */
    private function calculateCore(
        int $busRisk,
        float $uncoveredRatio,
        float $siloRatio,
        float $absenceImpact,
        OrganizationSetting $settings,
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

        return max(0.0, min(100.0, $fragility));
    }

    /* ════════════════ LAYER 1 · RAW — matrix → scalars (no DB writes) ════════════════ */

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
        // Horizon 0 — baseline reflects today's availability; horizon absences feed absence_impact below.
        $matrix = $this->coverage->getCoverage($project, $absentUserIds, $presentUserIds, 0);
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

        return $this->calculateCore($busRisk, $uncoveredRatio, $siloRatio, $absenceImpact, $settings);
    }

    /**
     * Newly-uncovered ratio when horizon absences are projected on top of the live state.
     * Builds the absence_impact scalar consumed by computeRawForProject.
     * $presentUserIds keeps forced-present users available in the projection (clean baseline isolation).
     */
    private function computeAbsenceImpactRatio(Project $project, array $baselineMatrix, array $absentUserIds, OrganizationSetting $settings, array $presentUserIds = []): float
    {
        $total = count($baselineMatrix);
        if ($total === 0) return 0.0;

        $horizonAbsent = $this->absences->getHorizonAbsentUserIdsForProject($project, (int) $settings->absence_horizon_days);
        $merged = array_values(array_unique(array_merge($absentUserIds, $horizonAbsent)));

        if ($merged === $absentUserIds) return 0.0;

        // Horizon absences arrive via $merged — keep the matrix itself at horizon 0.
        $with = $this->coverage->getCoverage($project, $merged, $presentUserIds, 0);
        $newlyUncovered = 0;
        foreach ($with as $sid => $row) {
            if ($row['status'] === 'uncovered' && ($baselineMatrix[$sid]['status'] ?? 'uncovered') !== 'uncovered') {
                $newlyUncovered++;
            }
        }
        return $newlyUncovered / $total;
    }

    /* ════════════════ SCOPE · PERSIST — compute then write ════════════════ */

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
}
