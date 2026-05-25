<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\User;

/**
 * Unique-skill-holders metric — count of users who hold at least one skill no one else holds org-wide.
 * Org-scope only.
 */
class UniqueSkillHoldersCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  Pure raw count of sole-holders.
     * </summary>
     *
     * @return int
     */
    public function computeRawForOrg(): int
    {
        return User::query()
            ->whereHas('skills', function ($q) {
                $q->whereIn('skills.id', function ($sub) {
                    $sub->from('user_skills')
                        ->select('skill_id')
                        ->groupBy('skill_id')
                        ->havingRaw('count(distinct user_id) = 1');
                });
            })
            ->count();
    }

    /**
     * <summary>
     *  Persist org-scope unique-skill-holders snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $count = $this->computeRawForOrg();
        $stat = new Stat(
            value: "{$count} " . ($count === 1 ? 'sole holder' : 'sole holders'),
            valueRaw: $count,
            severity: $count > 0 ? Severity::WARNING : Severity::OK,
            insight: 'Skill held by one user',
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::UsersUniqueSkillHolders, $stat);
    }
}
