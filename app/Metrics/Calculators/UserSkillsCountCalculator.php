<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\User;

/**
 * User-skills-count metric — count of skills attached to a user. Live query (no cached col).
 * Snapshot only (for history). User-scope only.
 */
class UserSkillsCountCalculator
{
    public function __construct(
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * @param User $user
     * @return int
     */
    public function computeRawForUser(User $user): int
    {
        return $user->skills()->count();
    }

    /**
     * <summary>
     *  Persist user skills-count snapshot. Insight = distinct category count.
     * </summary>
     *
     * @param User $user
     * @return MetricSnapshot
     * @throws \Throwable
     */
    public function forUser(User $user): MetricSnapshot
    {
        $user->loadMissing('skills.category');
        $total = $user->skills->count();
        $catCount = $user->skills
            ->groupBy(fn($s) => $s->category?->name ?? 'Uncategorized')
            ->count();

        $stat = new Stat(
            value: $total === 0 ? 'None' : (string) $total,
            valueRaw: $total,
            severity: Severity::OK,
            insight: $catCount > 0 ? "{$catCount} " . ($catCount === 1 ? 'category' : 'categories') : null,
        );

        return $this->metricsManager->persistUserMetric($user, null, MetricKey::SkillsCount, $stat);
    }
}
