<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;

/**
 * Projects-total metric — count of non-archived projects. Org-scope only.
 */
class ProjectsTotalCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * @return int
     */
    public function computeRawForOrg(): int
    {
        return Project::query()->whereNull('archived_at')->count();
    }

    /**
     * <summary>
     *  Persist org-scope projects-total snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $total = $this->computeRawForOrg();
        $stat = new Stat(
            value: "{$total} " . ($total === 1 ? 'project' : 'projects'),
            valueRaw: $total,
            severity: Severity::OK,
            insight: 'Active projects',
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::ProjectsTotal, $stat);
    }
}
