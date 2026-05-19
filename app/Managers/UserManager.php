<?php

namespace App\Managers;

use App\Enums\UserStatus;
use App\Jobs\RecalculateProjectRiskJob;
use App\Metrics\CriticalityScale;
use App\Models\User;
use App\Services\RiskCalculationService;
use App\Services\UserService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserManager
{
    public function __construct(
        private readonly RiskCalculationService $riskService,
        private readonly UserService $userService,
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
        return $this->riskService->computeUserCriticality($user);
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
     *  Assemble per-user stats: criticality score, bus-factor exposure, skill distribution, active projects.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return array criticality, bus_factor_in_org, skills, active_projects
     */
    /**
     * <summary>
     *  Org-wide user stats: headcount, today availability, critical users (criticality &gt; threshold),
     *  unique skill holders, department balance.
     *  Composite — combines UserService aggregates with per-user criticality from RiskCalculationService.
     * </summary>
     *
     * @return array total, available, away, critical_users{count, users}, unique_skill_holders, departments
     */
    public function getUsersStats(): array
    {
        $base = $this->userService->getOrgUserStats();

        $criticalUsers = User::with(['skills', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences'])
            ->get()
            ->map(fn(User $u) => [
                'user'        => $u,
                'criticality' => $this->riskService->computeUserCriticality($u),
            ])
            ->filter(fn(array $row) => $row['criticality']['score'] >= 50)
            ->sortByDesc(fn(array $row) => $row['criticality']['score'])
            ->values();

        $criticalCount = $criticalUsers->count();

        return array_merge($base, [
            'critical_users' => [
                'count'    => $criticalCount,
                'severity' => $criticalCount > 0 ? 'critical' : 'ok',
                'users'    => $criticalUsers->take(10)->map(fn(array $row) => [
                    'id'       => $row['user']->id,
                    'name'     => trim(($row['user']->firstname ?? '') . ' ' . ($row['user']->lastname ?? '')),
                    'title'    => $row['user']->title,
                    'score'    => $row['criticality']['score'],
                    'severity' => CriticalityScale::fromRaw($row['criticality']['score'])->severity()->value,
                ])->all(),
            ],
        ]);
    }

    public function getUserStats(User $user): array
    {
        $user->loadMissing(['skills.category', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences']);

        $criticality = $this->riskService->computeUserCriticality($user);
        $activeProjects = $user->projects->filter(
            fn($p) => $p->started_at !== null
                && $p->started_at <= now()
                && $p->paused_at === null
                && $p->completed_at === null
                && $p->archived_at === null
        );

        $busFactorProjects = $activeProjects->filter(
            fn($p) => $this->riskService->computeBusFactor($p) <= 2
        );

        $byCategory = $user->skills
            ->groupBy(fn($s) => $s->category?->name ?? 'Uncategorized')
            ->map(fn($skills, $cat) => [
                'category' => $cat,
                'count' => $skills->count(),
                'avg_level' => round($skills->avg(fn($s) => $s->pivot->level), 1),
            ])
            ->values();

        return [
            'criticality' => array_merge($criticality, [
                'severity' => CriticalityScale::fromRaw($criticality['score'])->severity()->value,
            ]),
            'bus_factor_in_org' => [
                'count' => $busFactorProjects->count(),
                'projects' => $busFactorProjects->map(fn($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ],
            'skills' => [
                'total' => $user->skills->count(),
                'by_category' => $byCategory->all(),
            ],
            'active_projects' => [
                'count' => $activeProjects->count(),
                'projects' => $activeProjects->map(fn($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            ],
        ];
    }

    private function dispatchProjectRecalculations(User $user): void
    {
        $user->loadMissing('projects');

        foreach ($user->projects as $project) {
            RecalculateProjectRiskJob::dispatch($project);
        }
    }
}
