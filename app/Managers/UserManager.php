<?php

namespace App\Managers;

use App\DTO\Stats\UserStats;
use App\DTO\Stats\UsersStats;
use App\Enums\UserStatus;
use App\Jobs\RecalculateProjectRiskJob;
use App\Metrics\Calculators\UserBusFactorInOrgCalculator;
use App\Metrics\Calculators\UserCriticalityCalculator;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricScope;
use App\Metrics\Snapshots\MetricSnapshotService;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserManager
{
    public function __construct(
        private readonly UserService $userService,
        private readonly MetricSnapshotService $snapshotService,
        private readonly UserCriticalityCalculator $criticalityCalculator,
        private readonly UserBusFactorInOrgCalculator $busFactorInOrgCalculator,
    ) {}

    /**
     * <summary>
     *  Retrieve paginated, filterable, sortable list of users via Spatie QueryBuilder.
     * </summary>
     *
     * @param Request $request Pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated users with department and skills
     */
    public function getAgileUsers(Request $request): LengthAwarePaginator
    {
        return $this->userService->getAgileUsers($request);
    }

    /**
     * <summary>
     *  Create a new user record inside a transaction.
     * </summary>
     *
     * @param array $data Validated fields: name, email, title, department_id
     * @return User Newly created user
     * @throws Throwable
     */
    public function createUser(array $data): User
    {
        return DB::transaction(fn() => $this->userService->createUser($data));
    }

    /**
     * <summary>
     *  Update fields on an existing user inside a transaction.
     * </summary>
     *
     * @param User  $user Route-model bound user
     * @param array $data Validated fields to update
     * @return User Updated user with department relation
     * @throws Throwable
     */
    public function updateUser(User $user, array $data): User
    {
        return DB::transaction(fn() => $this->userService->updateUser($user, $data));
    }

    /**
     * <summary>
     *  Delete a user inside a transaction.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return void
     * @throws Throwable
     */
    public function deleteUser(User $user): void
    {
        DB::transaction(fn() => $this->userService->deleteUser($user));
    }

    /**
     * <summary>
     *  Attach a skill to a user inside a transaction, then trigger project risk recalculations.
     * </summary>
     *
     * @param User $user    Route-model bound user
     * @param int  $skillId Target skill ID
     * @param int  $level   Proficiency level (1–5)
     * @return void
     * @throws Throwable
     */
    public function attachSkillToUser(User $user, int $skillId, int $level): void
    {
        DB::transaction(fn() => $this->userService->attachSkillToUser($user, $skillId, $level));

        $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Update the proficiency level of an attached skill inside a transaction, then trigger project risk recalculations.
     * </summary>
     *
     * @param User $user    Route-model bound user
     * @param int  $skillId Target skill ID
     * @param int  $level   New proficiency level (1–5)
     * @return void
     * @throws Throwable
     */
    public function updateUserSkill(User $user, int $skillId, int $level): void
    {
        DB::transaction(fn() => $this->userService->updateUserSkill($user, $skillId, $level));

        $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Detach a skill from a user inside a transaction, then trigger project risk recalculations.
     * </summary>
     *
     * @param User $user    Route-model bound user
     * @param int  $skillId Target skill ID
     * @return void
     * @throws Throwable
     */
    public function detachSkillFromUser(User $user, int $skillId): void
    {
        DB::transaction(fn() => $this->userService->detachSkillFromUser($user, $skillId));

        $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Compute the criticality score for a user across all active projects.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return array Criticality breakdown: silo_count, bus_factor_contributions, score
     */
    public function getUserCriticality(User $user): array
    {
        return $this->userService->getUserCriticality($user);
    }

    /**
     * <summary>
     *  Assemble today's team availability snapshot.
     *  Delegates raw fetch to UserService, then computes capacity percentage and top-5 preview.
     * </summary>
     *
     * @return array capacity_pct, total, employees (top-5 preview sorted by absence first)
     */
    public function getUsersTodayStatus(): array
    {
        $today = now()->toDateString();
        $users = $this->userService->getTodayUsers($today);

        $total = $users->count();
        $availableCount = $users->where('today_status', UserStatus::Available->value)->count();
        $capacityPct = $total > 0 ? (int) round(($availableCount / $total) * 100) : 100;

        $statusOrder = [UserStatus::Away->value => 0, UserStatus::Available->value => 1];
        $preview = $users
            ->sortBy(fn($u) => $statusOrder[$u['today_status']] ?? 99)
            ->values()
            ->take(5);

        return [
            'capacity_pct' => $capacityPct,
            'total' => $total,
            'employees' => $preview->values()->all(),
        ];
    }

    /**
     * <summary>
     *  Assemble the typed UsersStats DTO for GET /users/stats.
     *  Orchestrates UserService — one Service call per metric.
     * </summary>
     *
     * @return UsersStats total, available, critical_users, unique_skill_holders
     */
    public function getUsersStats(): UsersStats
    {
        return new UsersStats(
            total: $this->userService->getUsersTotalStat(),
            available: $this->userService->getUsersAvailableStat(),
            criticalUsers: $this->userService->getCriticalUsersStat(),
            uniqueSkillHolders: $this->userService->getUniqueSkillHoldersStat(),
        );
    }

    /**
     * <summary>
     *  Assemble the typed UserStats DTO for GET /users/{user}/stats.
     *  Orchestrates UserService — one Service call per metric.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return UserStats criticality, bus_factor_in_org, skills, active_projects
     */
    public function getUserStats(User $user): UserStats
    {
        return new UserStats(
            criticality: $this->userService->getUserCriticalityStat($user),
            busFactorInOrg: $this->userService->getUserBusFactorInOrgStat($user),
            skills: $this->userService->getUserSkillsStat($user),
            activeProjects: $this->userService->getUserActiveProjectsStat($user),
        );
    }

    private function dispatchProjectRecalculations(User $user): void
    {
        $user->loadMissing('projects');

        foreach ($user->projects as $project) {
            RecalculateProjectRiskJob::dispatch($project);
        }
    }
}
