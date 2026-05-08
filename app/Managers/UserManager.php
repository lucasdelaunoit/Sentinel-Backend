<?php

namespace App\Managers;

use App\Enums\UserStatus;
use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\UserService;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserManager
{
    public function __construct(
        private readonly RiskCalculationService $risk,
        private readonly UserService            $userService,
        private readonly SkillCoverageService   $coverage,
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
     *  Create a new user record.
     * </summary>
     *
     * @param array $data Validated fields: name, email, title, department_id
     * @return User Newly created user
     */
    public function createUser(array $data): User
    {
        return DB::transaction(fn() => User::create($data));
    }

    /**
     * <summary>
     *  Retrieve a single user with all relations loaded.
     * </summary>
     *
     * @param User $user User model instance
     * @return User User with department, skills (+ category), projects and leaves
     */
    public function getUser(User $user): User
    {
        return $user->loadMissing([
            'department',
            'skills.category',
            'projects',
            'leaves',
        ]);
    }

    /**
     * <summary>
     *  Update fields on an existing user.
     * </summary>
     *
     * @param User  $user User model instance
     * @param array $data Validated fields to update
     * @return User Updated user with department relation
     */
    public function updateUser(User $user, array $data): User
    {
        $user->update($data);

        return $user->fresh(['department']);
    }

    /**
     * <summary>
     *  Delete a user.
     * </summary>
     *
     * @param User $user User model instance
     * @return void
     */
    public function deleteUser(User $user): void
    {
        $user->delete();
    }

    /**
     * <summary>
     *  List all skills attached to a user with their proficiency levels.
     * </summary>
     *
     * @param User $user User model instance
     * @return Collection Each item: id, name, category, level
     */
    public function getUserSkills(User $user): Collection
    {
        $user->loadMissing('skills.category');

        return $user->skills->map(fn($skill) => [
            'id'       => $skill->id,
            'name'     => $skill->name,
            'category' => $skill->category?->name,
            'level'    => $skill->pivot->level,
        ]);
    }

    /**
     * <summary>
     *  Attach a skill to a user at a given proficiency level.
     *  Dispatches project risk recalculation jobs after attachment.
     * </summary>
     *
     * @param User $user    User model instance
     * @param int  $skillId Target skill ID
     * @param int  $level   Proficiency level (1–5)
     * @return void
     */
    public function attachSkillToUser(User $user, int $skillId, int $level): void
    {
        DB::transaction(function () use ($user, $skillId, $level) {
            $user->skills()->syncWithoutDetaching([$skillId => ['level' => $level]]);
        });

        $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Update the proficiency level of an already-attached skill.
     *  Dispatches project risk recalculation jobs after update.
     * </summary>
     *
     * @param User $user    User model instance
     * @param int  $skillId Target skill ID
     * @param int  $level   New proficiency level (1–5)
     * @return void
     */
    public function updateUserSkill(User $user, int $skillId, int $level): void
    {
        DB::transaction(function () use ($user, $skillId, $level) {
            $user->skills()->updateExistingPivot($skillId, ['level' => $level]);
        });

        $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Detach a skill from a user.
     *  Dispatches project risk recalculation jobs after detachment.
     * </summary>
     *
     * @param User $user    User model instance
     * @param int  $skillId Target skill ID
     * @return void
     */
    public function detachSkillFromUser(User $user, int $skillId): void
    {
        DB::transaction(function () use ($user, $skillId) {
            $user->skills()->detach($skillId);
        });

        $this->dispatchProjectRecalculations($user);
    }

    /**
     * <summary>
     *  Compute the criticality score for a user across all active projects.
     * </summary>
     *
     * @param User $user User model instance
     * @return array Criticality breakdown: silo_count, bus_factor_contributions, score
     */
    public function getUserCriticality(User $user): array
    {
        return $this->risk->computeUserCriticality($user);
    }

    /**
     * <summary>
     *  Get today's availability status for all users.
     *  Returns a capacity percentage and a top-5 preview sorted by absence first.
     * </summary>
     *
     * @return array capacity_pct, total, employees (top-5 preview)
     */
    public function getUsersTodayStatus(): array
    {
        $today = now()->toDateString();

        $users = User::query()
            ->with(['leaves' => fn($q) => $q
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
            ])
            ->orderBy('name')
            ->get()
            ->map(fn($user) => [
                'id'           => $user->id,
                'name'         => $user->name,
                'role'         => $user->title,
                'initials'     => $this->deriveInitials($user->name),
                'today_status' => $this->resolveStatus($user)->value,
            ]);

        $total          = $users->count();
        $availableCount = $users->where('today_status', UserStatus::Available->value)->count();
        $capacityPct    = $total > 0 ? (int) round(($availableCount / $total) * 100) : 100;

        $statusOrder = [UserStatus::Away->value => 0, UserStatus::Available->value => 1];
        $preview     = $users
            ->sortBy(fn($u) => $statusOrder[$u['today_status']] ?? 99)
            ->values()
            ->take(5);

        return [
            'capacity_pct' => $capacityPct,
            'total'        => $total,
            'employees'    => $preview->values()->all(),
        ];
    }

    /**
     * <summary>
     *  Get aggregate employee statistics for dashboard KPIs.
     * </summary>
     *
     * @return array total_employees, critical_employees, skill_coverage, department_balance
     */
    public function getUserStats(): array
    {
        return [
            'total_employees'    => $this->totalEmployeesStat(),
            'critical_employees' => $this->criticalEmployeesStat(),
            'skill_coverage'     => $this->skillCoverageStat(),
            'department_balance' => $this->departmentBalanceStat(),
        ];
    }

    private function totalEmployeesStat(): array
    {
        $count     = User::count();
        $deptCount = Department::whereHas('users')->count();

        return [
            'value'    => $count,
            'insight'  => $deptCount > 0
                ? "Across {$deptCount} department" . ($deptCount > 1 ? 's' : '')
                : "No departments assigned",
            'severity' => 'ok',
        ];
    }

    private function criticalEmployeesStat(): array
    {
        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'users.skills', 'users.leaves'])
            ->get();

        $criticalIds = collect();

        foreach ($projects as $project) {
            foreach ($this->coverage->getCoverage($project) as $skill) {
                if ($skill['status'] === 'siloed' && !empty($skill['employees'])) {
                    $criticalIds->push($skill['employees'][0]['user_id']);
                }
            }
        }

        $criticalIds = $criticalIds->unique();
        $count       = $criticalIds->count();
        $total       = User::count();
        $pct         = $total > 0 ? (int) round(($count / $total) * 100) : 0;

        $severity = match (true) {
            $count >= 5 => 'critical',
            $count >= 2 => 'warning',
            default     => 'ok',
        };

        return [
            'value'    => $count,
            'insight'  => $count > 0
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

        $total   = 0;
        $covered = 0;

        foreach ($projects as $project) {
            foreach ($this->coverage->getCoverage($project) as $skill) {
                $total++;
                if ($skill['status'] !== 'uncovered') {
                    $covered++;
                }
            }
        }

        $pct       = $total > 0 ? (int) round(($covered / $total) * 100) : 100;
        $uncovered = $total - $covered;

        $severity = match (true) {
            $pct < 70  => 'critical',
            $pct < 85  => 'warning',
            default    => 'ok',
        };

        return [
            'value'    => $pct,
            'insight'  => $uncovered > 0
                ? "{$uncovered} critical gap" . ($uncovered > 1 ? 's' : '')
                : "All required skills covered",
            'severity' => $severity,
        ];
    }

    private function departmentBalanceStat(): array
    {
        $departments = Department::withCount('users')->get();
        $total       = $departments->sum('users_count');

        if ($total === 0 || $departments->isEmpty()) {
            return [
                'value'    => 'Balanced',
                'insight'  => 'No users assigned',
                'severity' => 'ok',
            ];
        }

        $top      = $departments->sortByDesc('users_count')->first();
        $maxShare = $top->users_count / $total;
        $maxPct   = (int) round($maxShare * 100);

        [$label, $severity] = match (true) {
            $maxShare > 0.60 => ['Imbalanced', 'critical'],
            $maxShare > 0.40 => ['Skewed', 'warning'],
            default          => ['Balanced', 'ok'],
        };

        return [
            'value'    => $label,
            'insight'  => "{$top->name} {$maxPct}% of headcount",
            'severity' => $severity,
        ];
    }

    private function resolveStatus(User $user): UserStatus
    {
        return $user->leaves->isNotEmpty() ? UserStatus::Away : UserStatus::Available;
    }

    private function deriveInitials(string $name): string
    {
        $parts    = array_filter(explode(' ', trim($name)));
        $initials = array_map(fn($p) => strtoupper(mb_substr($p, 0, 1)), array_values($parts));

        return implode('', array_slice($initials, 0, 2));
    }

    private function dispatchProjectRecalculations(User $user): void
    {
        $user->loadMissing('projects');

        foreach ($user->projects as $project) {
            RecalculateProjectRiskJob::dispatch($project);
        }
    }
}
