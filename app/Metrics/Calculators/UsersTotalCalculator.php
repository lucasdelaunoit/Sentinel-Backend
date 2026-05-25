<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\User;

/**
 * Users-total metric — headcount. Org-scope only.
 */
class UsersTotalCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * @return int
     */
    public function computeRawForOrg(): int
    {
        return User::count();
    }

    /**
     * <summary>
     *  Persist org-scope users-total snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $total = $this->computeRawForOrg();
        $stat = new Stat(
            value: "{$total} " . ($total === 1 ? 'user' : 'users'),
            valueRaw: $total,
            severity: Severity::OK,
            insight: 'Headcount',
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::UsersTotal, $stat);
    }
}
