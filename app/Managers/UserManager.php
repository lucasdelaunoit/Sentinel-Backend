<?php

namespace App\Managers;

use App\DTO\Stats\UserStats;
use App\DTO\Stats\UsersStats;
use App\Jobs\RecalculateProjectRiskJob;
use App\Metrics\Calculators\BusFactorCalculator;
use App\Metrics\Calculators\CriticalityCalculator;
use App\Metrics\Calculators\UniqueSkillHoldersCalculator;
use App\Metrics\Calculators\UserActiveProjectsCalculator;
use App\Metrics\Calculators\UserSkillsCountCalculator;
use App\Metrics\Calculators\UsersAvailableCalculator;
use App\Metrics\Calculators\UsersTotalCalculator;
use App\Models\Project;
use App\Models\User;
use App\Services\UserService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserManager
{
    public function __construct(
        private readonly UserService $userService,
        private readonly CriticalityCalculator $criticalityCalculator,
        private readonly BusFactorCalculator $busFactorCalculator,
        private readonly UserSkillsCountCalculator $userSkillsCountCalculator,
        private readonly UserActiveProjectsCalculator $userActiveProjectsCalculator,
        private readonly UsersTotalCalculator $usersTotalCalculator,
        private readonly UsersAvailableCalculator $usersAvailableCalculator,
        private readonly UniqueSkillHoldersCalculator $uniqueSkillHoldersCalculator,
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
     *  Retrieve a single user with department eager-loaded.
     * </summary>
     *
     * @param User $user Target user
     * @return User User with department loaded
     */
    public function getUser(User $user): User
    {
        return $this->userService->getUser($user);
    }

    /**
     * <summary>
     *  Retrieve the paginated, filterable, sortable list of users assigned to a project via Spatie QueryBuilder.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter, sort & search parameters
     * @param Project $project Target project whose team is listed
     * @return LengthAwarePaginator Paginated project users with department and skills.category
     */
    public function getAgileUsersForProject(QueryParams $params, Project $project): LengthAwarePaginator
    {
        return $this->userService->getAgileUsersForProject($params, $project);
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
     *  Compute the org's present-capacity percentage for today: share of users with no active absence.
     *  Orchestrates two UserService counts (total + absent), then derives the percentage.
     * </summary>
     *
     * @return array{capacity_pct: int} Percentage (0–100) of users present today; 100 when the org has no users
     */
    public function getUsersCapacity(): array
    {
        $today = now()->toDateString();

        $total = $this->userService->countAllUsers();
        $absent = $this->userService->countUsersAbsentOn($today);

        $capacityPct = $total > 0 ? (int) round((($total - $absent) / $total) * 100) : 100;

        return ['capacity_pct' => $capacityPct];
    }

    /**
     * <summary>
     *  Capture the 4 user-scope snapshots for one user. Each Calculator owns its own transaction.
     *  Not wired to a trigger yet — call from a future recalc job / observer.
     * </summary>
     *
     * @param User $user
     * @return void
     * @throws Throwable When any Calculator transaction fails
     */
    public function captureUserStatsSnapshots(User $user): void
    {
        $this->criticalityCalculator->forUser($user);
        $this->busFactorCalculator->forUser($user);
        $this->userSkillsCountCalculator->forUser($user);
        $this->userActiveProjectsCalculator->forUser($user);
    }

    /**
     * <summary>
     *  Capture the 4 org-scope users-stats snapshots. Each Calculator owns its own transaction.
     *  Not wired to a trigger yet — call from a future cron / org-recalc job.
     * </summary>
     *
     * @return void
     * @throws Throwable When any Calculator transaction fails
     */
    public function captureUsersStatsSnapshots(): void
    {
        $this->usersTotalCalculator->forOrg();
        $this->usersAvailableCalculator->forOrg();
        $this->criticalityCalculator->forOrg();
        $this->uniqueSkillHoldersCalculator->forOrg();
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
