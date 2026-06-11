<?php

namespace App\Managers;

use App\DTO\Stats\DashboardStats;
use App\DTO\Stats\KnowledgeCoverageBreakdown;
use App\Metrics\Calculators\AbsenceImpactCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Metrics\Calculators\KnowledgeCoverageCalculator;
use App\Metrics\Calculators\TeamAvailabilityCalculator;
use App\Metrics\Scales\FragilityScale;
use App\Metrics\Scales\KnowledgeCoverageScale;
use App\Metrics\Severity;
use App\Models\Absence;
use App\Models\Project;
use App\Services\AbsenceService;
use App\Services\OrganizationSettingService;
use App\Services\ProjectService;
use App\Services\SkillCoverageService;
use App\Services\UserService;
use Throwable;

class DashboardManager
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly UserService $userService,
        private readonly AbsenceService $absenceService,
        private readonly OrganizationSettingService $organizationSettingService,
        private readonly FragilityCalculator $fragilityCalculator,
        private readonly KnowledgeCoverageCalculator $knowledgeCoverageCalculator,
        private readonly TeamAvailabilityCalculator $teamAvailabilityCalculator,
        private readonly AbsenceImpactCalculator $absenceImpactCalculator,
        private readonly SkillCoverageService $skillCoverageService,
    ) {}

    /**
     * <summary>
     *  Assemble the typed DashboardStats DTO for GET /dashboard/stats.
     *  Each Stat is read from the latest org-scope MetricSnapshot row.
     * </summary>
     *
     * @return DashboardStats fragile_projects, knowledge_coverage, team_availability, absence_impact
     */
    public function getTodayStats(): DashboardStats
    {
        return new DashboardStats(
            fragileProjects: $this->projectService->getWorstFragilityStat(),
            knowledgeCoverage: $this->projectService->getKnowledgeCoverageStat(),
            teamAvailability: $this->userService->getTeamAvailabilityStat(),
            absenceImpact: $this->projectService->getAbsenceImpactStat(),
        );
    }

    /**
     * <summary>
     *  Per-skill-category knowledge-coverage breakdown for GET /dashboard/knowledge-coverage (competency radar).
     *  Read-only — derives live from each active project's coverage matrix, writes no snapshot.
     * </summary>
     *
     * @return KnowledgeCoverageBreakdown categories[] (one per skill category) + most_fragile
     */
    public function getKnowledgeCoverage(): KnowledgeCoverageBreakdown
    {
        return $this->skillCoverageService->getKnowledgeCoverage();
    }

    /**
     * <summary>
     *  Upcoming Risk Events card for GET /dashboard/upcoming-risk-events. For each upcoming absence
     *  in the horizon, computes the operational impact on every project the absent user is on:
     *  bus-factor + knowledge-coverage before/after removing them, and which required skills fall to
     *  siloed/uncovered (lost_skills). Reuses the live calculators with the user as a virtual absence —
     *  no parallel formulas, writes nothing. Read-only.
     *
     *  Events with no measurable project impact (empty affected_projects) are dropped — the card lists
     *  risks, not every absence. The 'before' matrix forces the absent user present (see
     *  buildProjectImpact) so the delta is real even inside absence_horizon_days.
     * </summary>
     *
     * @param int $horizonDays Forward window for which absences to surface
     * @return array{generated_at:string, events:array<int, array<string, mixed>>}
     */
    public function getUpcomingRiskEvents(int $horizonDays): array
    {
        $criticalBusThreshold = (int) $this->organizationSettingService
            ->getOrganizationSetting()->critical_bus_factor_threshold;
        $baseline = $this->projectService->getActiveProjectsMetricBaseline();

        $events = $this->absenceService->getUpcomingAbsences($horizonDays)
            ->map(fn(Absence $absence) => $this->buildRiskEvent($absence, $criticalBusThreshold, $baseline))
            ->filter(fn(array $event) => $event['affected_projects'] !== [])
            ->values()
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'events' => $events,
        ];
    }

    /**
     * <summary>
     *  Build one RiskEvent payload from an absence: maps its type to a kind, computes the per-project
     *  impacts and folds them into a single event severity.
     * </summary>
     *
     * @param Absence $absence Upcoming absence with user + projects eager-loaded
     * @param int $criticalBusThreshold OrganizationSetting.critical_bus_factor_threshold
     * @param array{count:int, avg_fragility:float, avg_knowledge_coverage:float} $baseline Current org metric baseline
     * @return array<string, mixed> RiskEvent shape consumed by the frontend
     */
    private function buildRiskEvent(Absence $absence, int $criticalBusThreshold, array $baseline): array
    {
        $user = $absence->user;

        $affected = $user->projects
            ->map(fn(Project $project) => $this->buildProjectImpact($project, $user->id, $criticalBusThreshold))
            ->filter()
            ->values();

        return [
            'id' => "evt_{$absence->id}",
            'date' => $absence->start_date->toDateString(),
            'employee' => [
                'id' => (string) $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
            ],
            'kind' => 'leave',
            'severity' => $this->resolveEventSeverity($affected->all()),
            'org_impact' => $this->buildOrgImpact($affected->all(), $baseline),
            'affected_projects' => $affected
                ->map(fn(array $p) => collect($p)->except('severity')->all())
                ->all(),
        ];
    }

    /**
     * <summary>
     *  Estimated headline impact of one absence at two scopes. 'affected' = average across only the
     *  projects the person is on (punchy — what their own work loses). 'org' = whole-org average, where
     *  the same per-project deltas are spread over every non-archived project (small — one person is a
     *  slice of the org). Higher fragility = worse; lower knowledge coverage = worse. Tiers from the
     *  shared Scale enums. Pure estimation — no writes.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $affectedProjects Per-project impact rows
     * @param array{count:int, avg_fragility:float, avg_knowledge_coverage:float} $baseline Current org metric baseline
     * @return array{affected:array<string,array<string,mixed>>, org:array<string,array<string,mixed>>}
     */
    private function buildOrgImpact(array $affectedProjects, array $baseline): array
    {
        $n = max(1, count($affectedProjects));
        $orgCount = $baseline['count'];

        $fragBeforeSum = 0.0;
        $fragAfterSum = 0.0;
        $coverageBeforeSum = 0.0;
        $coverageAfterSum = 0.0;
        foreach ($affectedProjects as $project) {
            $fragBeforeSum += $project['fragility_before'];
            $fragAfterSum += $project['fragility_after'];
            $coverageBeforeSum += $project['coverage_before'];
            $coverageAfterSum += $project['coverage_after'];
        }

        return [
            'affected' => [
                'fragility' => $this->fragilityBlock($fragBeforeSum / $n, $fragAfterSum / $n),
                'knowledge_coverage' => $this->knowledgeCoverageBlock($coverageBeforeSum / $n, $coverageAfterSum / $n),
            ],
            'org' => [
                'fragility' => $this->fragilityBlock(
                    $baseline['avg_fragility'],
                    $baseline['avg_fragility'] + ($fragAfterSum - $fragBeforeSum) / $orgCount,
                ),
                'knowledge_coverage' => $this->knowledgeCoverageBlock(
                    $baseline['avg_knowledge_coverage'],
                    $baseline['avg_knowledge_coverage'] + ($coverageAfterSum - $coverageBeforeSum) / $orgCount,
                ),
            ],
        ];
    }

    /**
     * <summary>
     *  Fragility metric block (before/after rounded, delta, tier from the after value). Higher = worse.
     * </summary>
     *
     * @param float $before Raw fragility before the absence
     * @param float $after Raw fragility after the absence
     * @return array<string, mixed>
     */
    private function fragilityBlock(float $before, float $after): array
    {
        $beforeInt = (int) round(max(0.0, min(100.0, $before)));
        $afterInt = (int) round(max(0.0, min(100.0, $after)));
        $scale = FragilityScale::fromRaw($afterInt);

        return [
            'before' => $beforeInt,
            'after' => $afterInt,
            'delta' => $afterInt - $beforeInt,
            'tier' => $scale->value,
            'tier_label' => $scale->label(),
            'severity' => $scale->severity()->value,
        ];
    }

    /**
     * <summary>
     *  Knowledge-coverage metric block (before/after rounded, delta, tier from the after value). Lower = worse.
     * </summary>
     *
     * @param float $before Raw coverage % before the absence
     * @param float $after Raw coverage % after the absence
     * @return array<string, mixed>
     */
    private function knowledgeCoverageBlock(float $before, float $after): array
    {
        $beforeInt = (int) round(max(0.0, min(100.0, $before)));
        $afterInt = (int) round(max(0.0, min(100.0, $after)));
        $scale = KnowledgeCoverageScale::fromRaw($afterInt);

        return [
            'before' => $beforeInt,
            'after' => $afterInt,
            'delta' => $afterInt - $beforeInt,
            'tier' => $scale->value,
            'tier_label' => $scale->label(),
            'severity' => $scale->severity()->value,
        ];
    }

    /**
     * <summary>
     *  Per-project impact of removing one user: bus-factor & knowledge-coverage before/after plus the
     *  required skills that degrade to siloed/uncovered. The 'before' matrix forces the user present
     *  (overriding their own horizon-absence) so the delta isolates THIS absence even when it falls
     *  inside absence_horizon_days; the 'after' matrix marks them absent. Metrics are derived from the
     *  same coverage matrix the calculators use — single source. Returns null when nothing changes.
     * </summary>
     *
     * @param Project $project Project the user is assigned to
     * @param int $userId User going absent
     * @param int $criticalBusThreshold OrganizationSetting.critical_bus_factor_threshold
     * @return array<string, mixed>|null Impact row (with private 'severity' key) or null when no delta
     */
    private function buildProjectImpact(Project $project, int $userId, int $criticalBusThreshold): ?array
    {
        $before = $this->skillCoverageService->getCoverage($project, [], [$userId]);
        $after = $this->skillCoverageService->getCoverage($project, [$userId]);

        $rank = ['safe' => 0, 'siloed' => 1, 'uncovered' => 2];
        $lostSkills = [];
        foreach ($after as $skillId => $row) {
            $beforeStatus = $before[$skillId]['status'] ?? 'safe';
            if ($rank[$row['status']] > $rank[$beforeStatus]) {
                $lostSkills[] = $row['skill_name'];
            }
        }

        $busBefore = $this->busFactorFromMatrix($before);
        $busAfter = $this->busFactorFromMatrix($after);
        $coverageBefore = $this->coveragePctFromMatrix($before);
        $coverageAfter = $this->coveragePctFromMatrix($after);

        if ($lostSkills === [] && $busBefore === $busAfter && $coverageBefore === $coverageAfter) {
            return null;
        }

        // Fragility needs the full composite formula (weights, tolerance, rule penalty) — reuse the
        // calculator with the same present/absent rosters rather than re-deriving it from the matrix.
        $fragilityBefore = (int) round($this->fragilityCalculator->computeRawForProject($project, [], [$userId]));
        $fragilityAfter = (int) round($this->fragilityCalculator->computeRawForProject($project, [$userId]));

        return [
            'id' => (string) $project->id,
            'name' => $project->name,
            'fragility_before' => $fragilityBefore,
            'fragility_after' => $fragilityAfter,
            'coverage_before' => $coverageBefore,
            'coverage_after' => $coverageAfter,
            'bus_factor_before' => $busBefore,
            'bus_factor_after' => $busAfter,
            'lost_skills' => $lostSkills,
            'severity' => $this->resolveProjectSeverity($busAfter, $lostSkills, $criticalBusThreshold),
        ];
    }

    /**
     * <summary>
     *  Bus factor from a coverage matrix — min covering-user count across covered required skills,
     *  0 when every required skill is uncovered. Mirrors BusFactorCalculator core on the same matrix.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $matrix Coverage matrix from SkillCoverageService
     * @return int
     */
    private function busFactorFromMatrix(array $matrix): int
    {
        $counts = [];
        foreach ($matrix as $row) {
            $count = count($row['employees']);
            if ($count > 0) {
                $counts[] = $count;
            }
        }
        return $counts === [] ? 0 : min($counts);
    }

    /**
     * <summary>
     *  Knowledge coverage % from a coverage matrix — safe skills over total required, 100 when none.
     *  Mirrors KnowledgeCoverageCalculator core on the same matrix.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $matrix Coverage matrix from SkillCoverageService
     * @return int
     */
    private function coveragePctFromMatrix(array $matrix): int
    {
        $total = count($matrix);
        if ($total === 0) {
            return 100;
        }
        $safe = 0;
        foreach ($matrix as $row) {
            if ($row['status'] === 'safe') {
                $safe++;
            }
        }
        return (int) round(($safe / $total) * 100);
    }

    /**
     * <summary>
     *  Severity for a single affected project, driven by org thresholds.
     *  critical: a skill goes uncovered or bus factor drops to a single holder.
     *  warning: bus factor falls below the critical threshold, or some coverage is lost.
     *  ok: otherwise.
     * </summary>
     *
     * @param int $busAfter Bus factor after the absence
     * @param array<int, string> $lostSkills Skills degraded by the absence
     * @param int $criticalBusThreshold OrganizationSetting.critical_bus_factor_threshold
     * @return string Severity enum value (ok|warning|critical)
     */
    private function resolveProjectSeverity(int $busAfter, array $lostSkills, int $criticalBusThreshold): string
    {
        if ($busAfter <= 1) {
            return Severity::CRITICAL->value;
        }
        if ($busAfter < $criticalBusThreshold || $lostSkills !== []) {
            return Severity::WARNING->value;
        }
        return Severity::OK->value;
    }

    /**
     * <summary>
     *  Fold per-project severities into one event severity — the worst project wins. ok when the
     *  absence affects no project.
     * </summary>
     *
     * @param array<int, array<string, mixed>> $affectedProjects Impact rows (each with a 'severity' key)
     * @return string Severity enum value (ok|warning|critical)
     */
    private function resolveEventSeverity(array $affectedProjects): string
    {
        $order = [Severity::OK->value => 0, Severity::WARNING->value => 1, Severity::CRITICAL->value => 2];
        $worst = Severity::OK->value;
        foreach ($affectedProjects as $project) {
            if ($order[$project['severity']] > $order[$worst]) {
                $worst = $project['severity'];
            }
        }
        return $worst;
    }

    /**
     * <summary>
     *  Capture the 4 org-scope dashboard-stats snapshots. Each Calculator owns its own transaction.
     *  Worst-fragility is written as part of FragilityCalculator::forOrg() — call that separately
     *  (e.g. via ProjectManager::captureProjectsStatsSnapshots) rather than duplicating it here.
     *  Not wired to a trigger yet — call from a future cron / org-recalc job.
     * </summary>
     *
     * @return void
     * @throws Throwable When any Calculator transaction fails
     */
    public function captureDashboardStatsSnapshots(): void
    {
        $this->knowledgeCoverageCalculator->forOrg();
        $this->teamAvailabilityCalculator->forOrg();
        $this->absenceImpactCalculator->forOrg();
        // DashboardWorstFragility is part of FragilityCalculator::forOrg()
    }
}
