<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Scales\KnowledgeCoverageScale;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;
use App\Services\SkillCoverageService;

/**
 * Knowledge-coverage metric — % of required skills with at least one available owner
 * (safe + siloed; only 'uncovered' counts against). Silo fragility is captured by the
 * bus-factor / fragility metrics, not by coverage.
 *
 * Scopes:
 *  - forProject — per-project %. Persists projects.knowledge_coverage_raw + snapshot.
 *  - forOrg     — org-wide aggregate over active projects' matrices. Persists snapshot MetricKey::DashboardKnowledgeCoverage.
 */
class KnowledgeCoverageCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  CORE math. Covered count over total required skills. Returns 100.0 when total == 0.
     * </summary>
     *
     * @param int $covered
     * @param int $total
     * @return float
     */
    private function calculateCore(int $covered, int $total): float
    {
        if ($total === 0) return 100.0;
        return ($covered / $total) * 100;
    }

    /**
     * <summary>
     *  Pure raw % covered for a project.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return float
     */
    public function computeRawForProject(Project $project, array $absentUserIds = []): float
    {
        // Horizon 0 — baseline reflects today's availability; upcoming absences are projection inputs.
        $matrix = $this->coverage->getCoverage($project, $absentUserIds, [], 0);
        $total = count($matrix);
        $covered = 0;
        foreach ($matrix as $row) {
            if ($row['status'] !== 'uncovered') $covered++;
        }
        return $this->calculateCore($covered, $total);
    }

    /**
     * <summary>
     *  Persist project knowledge-coverage — updates projects.knowledge_coverage_raw + appends snapshot.
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
        $stat = Stat::display("{$raw}%", $raw, KnowledgeCoverageScale::fromRaw($raw), "{$raw}% covered");

        return $this->metricsManager->persistProjectMetric($project, 'knowledge_coverage_raw', MetricKey::KnowledgeCoverage, $stat);
    }

    /**
     * <summary>
     *  Persist org-wide dashboard knowledge-coverage snapshot. Walks every active project's matrix.
     *  Heavy compute — meant to run from snapshot writer / cron, not synchronously.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $total = 0;
        $covered = 0;
        foreach ($projects as $project) {
            foreach ($this->coverage->getCoverage($project, [], [], 0) as $skill) {
                $total++;
                if ($skill['status'] !== 'uncovered') $covered++;
            }
        }

        $pct = (int) round($this->calculateCore($covered, $total));
        $uncoveredCount = $total - $covered;
        $insight = $uncoveredCount > 0
            ? "{$uncoveredCount} skill" . ($uncoveredCount > 1 ? 's' : '') . ' uncovered'
            : 'All skills covered';

        $stat = Stat::display("{$pct}%", $pct, KnowledgeCoverageScale::fromRaw($pct), $insight);

        return $this->metricsManager->persistOrgMetric(MetricKey::DashboardKnowledgeCoverage, $stat);
    }
}
