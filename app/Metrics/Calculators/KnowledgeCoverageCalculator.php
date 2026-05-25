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
 * Knowledge-coverage metric — % of required skills with status 'safe' (siloed / uncovered count against).
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
     *  CORE math. Safe count over total required skills. Returns 100.0 when total == 0.
     * </summary>
     *
     * @param int $safe
     * @param int $total
     * @return float
     */
    private function calculateCore(int $safe, int $total): float
    {
        if ($total === 0) return 100.0;
        return ($safe / $total) * 100;
    }

    /**
     * <summary>
     *  Pure raw % safe for a project.
     * </summary>
     *
     * @param Project $project
     * @param array<int> $absentUserIds
     * @return float
     */
    public function computeRawForProject(Project $project, array $absentUserIds = []): float
    {
        $matrix = $this->coverage->getCoverage($project, $absentUserIds);
        $total = count($matrix);
        $safe = 0;
        foreach ($matrix as $row) {
            if ($row['status'] === 'safe') $safe++;
        }
        return $this->calculateCore($safe, $total);
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
        $stat = Stat::display("{$raw}%", $raw, KnowledgeCoverageScale::fromRaw($raw), "{$raw}% safe");

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
        $safe = 0;
        foreach ($projects as $project) {
            foreach ($this->coverage->getCoverage($project) as $skill) {
                $total++;
                if ($skill['status'] === 'safe') $safe++;
            }
        }

        $pct = (int) round($this->calculateCore($safe, $total));
        $underCovered = $total - $safe;
        $insight = $underCovered > 0
            ? "{$underCovered} skill" . ($underCovered > 1 ? 's' : '') . ' under-covered'
            : 'All skills covered';

        $stat = Stat::display("{$pct}%", $pct, KnowledgeCoverageScale::fromRaw($pct), $insight);

        return $this->metricsManager->persistOrgMetric(MetricKey::DashboardKnowledgeCoverage, $stat);
    }
}
