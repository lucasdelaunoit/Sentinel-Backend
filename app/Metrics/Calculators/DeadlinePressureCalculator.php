<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;

/**
 * Deadline-pressure metric — count of non-archived, non-completed projects with deadline in the next 14 days.
 * Org-scope only. Tier label by count: 0 None · 1-2 Low · 3-4 Moderate · 5+ High.
 */
class DeadlinePressureCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  Pure raw count of upcoming deadlines (next 14 days).
     * </summary>
     *
     * @return int
     */
    public function computeRawForOrg(): int
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(14);

        return Project::query()
            ->whereNull('archived_at')
            ->whereNull('completed_at')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [$today->toDateString(), $horizon->toDateString()])
            ->count();
    }

    /**
     * <summary>
     *  Persist org-scope deadline-pressure snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $count = $this->computeRawForOrg();

        [$label, $severity] = match (true) {
            $count >= 5 => ['High', Severity::CRITICAL],
            $count >= 3 => ['Moderate', Severity::WARNING],
            $count >= 1 => ['Low', Severity::WARNING],
            default => ['None', Severity::OK],
        };

        $stat = new Stat(
            value: $label,
            valueRaw: $count,
            severity: $severity,
            insight: $count > 0
                ? "{$count} deadline" . ($count > 1 ? 's' : '') . ' in the next 14 days'
                : 'No deadlines in the next 14 days',
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::ProjectsDeadlinePressure, $stat);
    }
}
