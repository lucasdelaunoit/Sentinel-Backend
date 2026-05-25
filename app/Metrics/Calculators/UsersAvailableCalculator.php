<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\User;

/**
 * Users-available metric — headcount minus users absent today. Org-scope only.
 * Distinct from TeamAvailabilityCalculator::forOrg (which produces a % and lives on the dashboard card).
 */
class UsersAvailableCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  Pure raw count of users available today.
     * </summary>
     *
     * @return int
     */
    public function computeRawForOrg(): int
    {
        $today = now()->toDateString();
        $total = User::count();
        $away = User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->count();

        return $total - $away;
    }

    /**
     * <summary>
     *  Persist org-scope users-available snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $today = now()->toDateString();
        $away = User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->count();
        $available = $this->computeRawForOrg();

        $stat = new Stat(
            value: "{$available} available",
            valueRaw: $available,
            severity: $away > 0 ? Severity::WARNING : Severity::OK,
            insight: $away > 0 ? "{$away} away today" : 'All present',
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::UsersAvailable, $stat);
    }
}
