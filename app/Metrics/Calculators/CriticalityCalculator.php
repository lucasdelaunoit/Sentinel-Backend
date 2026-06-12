<?php

namespace App\Metrics\Calculators;

use App\Managers\MetricsManager;
use App\Metrics\Scales\CriticalityScale;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricSnapshot;
use App\Metrics\Stat;
use App\Models\User;
use App\Services\OrganizationSettingService;
use App\Services\SkillCoverageService;
use Throwable;

/**
 * User criticality metric — composite 0-100 score blending unique-skill holding, silo participation,
 * and bus-factor contributions across active projects. Multi-scope.
 *
 * Scopes:
 *  - forUser — full breakdown {score, unique_skills, silo_count, bus_factor_projects}. Persists users.criticality_raw + snapshot.
 *  - forOrg  — count of users with score &gt;= 50. Persists snapshot MetricKey::UsersCritical.
 *
 * Fold-in note: this Calculator absorbs the former RiskCalculationService::computeUserCriticality —
 * that Service has been deleted to keep a single home per metric.
 */
class CriticalityCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly OrganizationSettingService $orgSettings,
        private readonly MetricsManager $metricsManager,
    ) {}

    /**
     * <summary>
     *  CORE math. Computes the full criticality breakdown for one user.
     * </summary>
     *
     * @param User $user Target user (relations preloaded inside)
     * @param int $threshold Critical bus-factor threshold from OrganizationSetting
     * @return array{score:int,unique_skills:int,silo_count:int,bus_factor_projects:int}
     */
    private function calculateCore(User $user, int $threshold): array
    {
        $user->loadMissing(['skills', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences']);

        $userSkillIds = $user->skills->pluck('id')->all();
        $uniqueCount = 0;
        if ($userSkillIds !== []) {
            $holderCounts = User::whereHas('skills', fn($q) => $q->whereIn('skills.id', $userSkillIds))
                ->join('user_skills', 'users.id', '=', 'user_skills.user_id')
                ->whereIn('user_skills.skill_id', $userSkillIds)
                ->selectRaw('user_skills.skill_id, count(distinct users.id) as c')
                ->groupBy('user_skills.skill_id')
                ->pluck('c', 'user_skills.skill_id');
            foreach ($userSkillIds as $skillId) {
                if ((int) ($holderCounts[$skillId] ?? 0) === 1) $uniqueCount++;
            }
        }

        $siloCount = 0;
        $busFactorProjects = 0;

        foreach ($user->projects as $project) {
            if ($project->archived_at !== null || $project->completed_at !== null) continue;

            $matrix = $this->coverage->getCoverage($project, [], [], 0);

            $smallest = PHP_INT_MAX;
            foreach ($matrix as $row) {
                $count = count($row['employees']);
                if ($count > 0 && $count < $smallest) $smallest = $count;
            }

            $isInSmallestCover = false;
            foreach ($matrix as $row) {
                $coveringUserIds = array_column($row['employees'], 'user_id');
                if (!in_array($user->id, $coveringUserIds, true)) continue;
                if ($row['status'] === 'siloed') $siloCount++;
                if ($smallest !== PHP_INT_MAX && count($coveringUserIds) === $smallest && $smallest <= $threshold) {
                    $isInSmallestCover = true;
                }
            }

            if ($isInSmallestCover) $busFactorProjects++;
        }

        $score = min(100, $uniqueCount * 25 + $siloCount * 10 + $busFactorProjects * 15);

        return [
            'score' => $score,
            'unique_skills' => $uniqueCount,
            'silo_count' => $siloCount,
            'bus_factor_projects' => $busFactorProjects,
        ];
    }

    /**
     * <summary>
     *  Pure raw breakdown for a user. No DB writes.
     * </summary>
     *
     * @param User $user
     * @return array{score:int,unique_skills:int,silo_count:int,bus_factor_projects:int}
     */
    public function computeRawForUser(User $user): array
    {
        $threshold = (int) $this->orgSettings->getOrganizationSetting()->critical_bus_factor_threshold;
        return $this->calculateCore($user, $threshold);
    }

    /**
     * <summary>
     *  Persist user criticality — updates users.criticality_raw + appends snapshot.
     * </summary>
     *
     * @param User $user
     * @return MetricSnapshot
     * @throws Throwable
     */
    public function forUser(User $user): MetricSnapshot
    {
        $breakdown = $this->computeRawForUser($user);
        $score = (int) $breakdown['score'];

        $stat = Stat::fromScale(
            CriticalityScale::fromRaw($score),
            $score,
            "Score: {$score}/100",
        );

        return $this->metricsManager->persistUserMetric($user, 'criticality_raw', MetricKey::Criticality, $stat);
    }

    /**
     * <summary>
     *  Pure raw — count of users with cached criticality_raw &gt;= 50. Reads the cached column.
     * </summary>
     *
     * @return int
     */
    public function computeRawForOrg(): int
    {
        return User::query()->where('criticality_raw', '>=', 50)->count();
    }

    /**
     * <summary>
     *  Persist org-scope critical-users count snapshot.
     * </summary>
     *
     * @return MetricSnapshot
     * @throws Throwable
     */
    public function forOrg(): MetricSnapshot
    {
        $count = $this->computeRawForOrg();
        $stat = new Stat(
            value: $count === 0 ? 'Safe' : "{$count} at-risk",
            valueRaw: $count,
            severity: $count > 0 ? Severity::CRITICAL : Severity::OK,
            insight: 'Criticality ≥ 50',
        );

        return $this->metricsManager->persistOrgMetric(MetricKey::UsersCritical, $stat);
    }
}
