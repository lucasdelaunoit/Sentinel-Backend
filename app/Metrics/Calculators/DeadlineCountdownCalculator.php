<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\Project;

/**
 * Deadline-countdown metric — days remaining until project deadline. Project-scoped only.
 * Negative raw = overdue. Completed projects return a frozen "Completed" Stat.
 */
class DeadlineCountdownCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  Pure raw days-until-deadline. Negative when overdue. Returns null when no deadline / completed.
     * </summary>
     *
     * @param Project $project
     * @return int|null
     */
    public function computeRawForProject(Project $project): ?int
    {
        if ($project->completed_at !== null) return null;
        if ($project->deadline === null) return null;

        return (int) round(now()->startOfDay()->diffInDays($project->deadline->startOfDay(), false));
    }

    /**
     * <summary>
     *  Persist project deadline-countdown snapshot only (no cached column — derived from deadline date).
     * </summary>
     *
     * @param Project $project
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forProject(Project $project): MetricSnapshot
    {
        $stat = $this->buildStat($project);

        return $this->metricsManager->persistProjectMetric($project, null, MetricKey::DeadlineCountdown, $stat);
    }

    private function buildStat(Project $project): Stat
    {
        if ($project->completed_at !== null) {
            return new Stat('Completed', 0, Severity::OK, 'Delivered');
        }
        if ($project->deadline === null) {
            return new Stat('No deadline', 0, Severity::OK, 'Untimed');
        }

        $days = $this->computeRawForProject($project);

        if ($days < 0) {
            $overdue = abs($days);
            return new Stat(
                value: 'Overdue',
                valueRaw: $days,
                severity: Severity::CRITICAL,
                insight: "{$overdue} day" . ($overdue > 1 ? 's' : '') . ' past deadline',
            );
        }

        $severity = match (true) {
            $days <= 7 => Severity::CRITICAL,
            $days <= 30 => Severity::WARNING,
            default => Severity::OK,
        };
        $insight = match (true) {
            $days === 0 => 'Due today',
            $days <= 7 => 'Crunch time',
            $days <= 30 => 'Closing in',
            default => 'On schedule',
        };

        return new Stat(
            value: $days === 0 ? 'Today' : "{$days} day" . ($days > 1 ? 's' : ''),
            valueRaw: $days,
            severity: $severity,
            insight: $insight,
        );
    }
}
