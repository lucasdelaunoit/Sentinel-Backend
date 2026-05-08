<?php

namespace App\Managers;

use App\Enums\UserStatus;
use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Project;
use App\Models\User;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use App\Services\UserService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserManager
{
    public function __construct(
        private readonly RiskCalculationService $riskService,
        private readonly SkillCoverageService   $coverageService,
        private readonly UserService            $userService,
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
     *  Retrieve a single user with all relations loaded.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return User User with department, skills (+ category), projects and leaves
     */
    public function getUser(User $user): User
    {
        return $this->userService->getUser($user);
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
     *  List all skills attached to a user with their proficiency levels.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return Collection Each item: id, name, category, level
     */
    public function getUserSkills(User $user): Collection
    {
        return $this->userService->getUserSkills($user);
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
     *  Assemble aggregate employee statistics for dashboard KPIs.
     *  Orchestrates UserService (total, balance) and SkillCoverageService (critical, coverage).
     * </summary>
     *
     * @return array total_employees, critical_employees, skill_coverage, department_balance
     */
    public function getUserStats(): array
    {
        return [
            'total_employees' => $this->userService->getTotalEmployeesStat(),
            'critical_employees' => $this->criticalEmployeesStat(),
            'skill_coverage' => $this->skillCoverageStat(),
            'department_balance' => $this->userService->getDepartmentBalanceStat(),
        ];
    }

    private function criticalEmployeesStat(): array
    {
        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'users.skills', 'users.leaves'])
            ->get();

        $criticalIds = collect();

        foreach ($projects as $project) {
            foreach ($this->coverageService->getCoverage($project) as $skill) {
                if ($skill['status'] === 'siloed' && !empty($skill['employees'])) {
                    $criticalIds->push($skill['employees'][0]['user_id']);
                }
            }
        }

        $criticalIds = $criticalIds->unique();
        $count = $criticalIds->count();
        $total = User::count();
        $pct = $total > 0 ? (int) round(($count / $total) * 100) : 0;

        $severity = match (true) {
            $count >= 5 => 'critical',
            $count >= 2 => 'warning',
            default => 'ok',
        };

        return [
            'value' => $count,
            'insight' => $count > 0
                ? "High-impact staff ({$pct}% of team)"
                : "No single-point-of-failure staff",
            'severity' => $severity,
        ];
    }

    private function skillCoverageStat(): array
    {
        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'users.skills', 'users.leaves'])
            ->get();

        $total = 0;
        $covered = 0;

        foreach ($projects as $project) {
            foreach ($this->coverageService->getCoverage($project) as $skill) {
                $total++;
                if ($skill['status'] !== 'uncovered') {
                    $covered++;
                }
            }
        }

        $pct       = $total > 0 ? (int) round(($covered / $total) * 100) : 100;
        $uncovered = $total - $covered;

        $severity = match (true) {
            $pct < 70 => 'critical',
            $pct < 85 => 'warning',
            default => 'ok',
        };

        return [
            'value' => $pct,
            'insight' => $uncovered > 0
                ? "{$uncovered} critical gap" . ($uncovered > 1 ? 's' : '')
                : "All required skills covered",
            'severity' => $severity,
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
