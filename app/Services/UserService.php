<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class UserService
{
    /**
     * <summary>
     *  Build a paginated, filterable, sortable query for users via Spatie QueryBuilder.
     *  Supports search (name/email), department, skill and status filters.
     * </summary>
     *
     * @param Request $request Pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated users with department and skills.category
     */
    public function getAgileUsers(Request $request): LengthAwarePaginator
    {
        if ($request->filled('search') && !$request->has('filter.search')) {
            $request->merge(['filter' => array_merge($request->input('filter', []), ['search' => $request->input('search')])]);
        }

        return QueryBuilder::for(User::class, $request)
            ->with(['department', 'skills.category'])
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(fn($q) => $q
                        ->where('firstname', 'like', "%{$value}%")
                        ->orWhere('lastname', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%")
                    );
                }),
                AllowedFilter::exact('department_id'),
                AllowedFilter::callback('skill_id', function ($query, $value) {
                    $query->whereHas('skills', fn($q) => $q->where('skills.id', $value));
                }),
                AllowedFilter::callback('status', function ($query, $value) {
                    $status = UserStatus::tryFrom($value);
                    if ($status === null) return;

                    $today      = now()->toDateString();
                    $hasAbsence = fn($q) => $q
                        ->where('start_date', '<=', $today)
                        ->where('end_date', '>=', $today);

                    if ($status === UserStatus::Away) {
                        $query->whereHas('absences', $hasAbsence);
                    } else {
                        $query->whereDoesntHave('absences', $hasAbsence);
                    }
                }),
            ])
            ->allowedSorts([
                AllowedSort::callback('name', function ($query, bool $descending) {
                    $dir = $descending ? 'desc' : 'asc';
                    $query->orderBy('firstname', $dir)->orderBy('lastname', $dir);
                }),
                AllowedSort::field('firstname'),
                AllowedSort::field('lastname'),
                AllowedSort::field('title'),
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('name')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());
    }

    /**
     * <summary>
     *  Persist a new user record.
     * </summary>
     *
     * @param array $data Validated fields: name, email, title, department_id
     * @return User Newly created user
     */
    public function createUser(array $data): User
    {
        return User::create($data);
    }

    /**
     * <summary>
     *  Apply field updates to an existing user and reload the department relation.
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
     *  Hard-delete a user record.
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
     *  Attach a skill to a user at a given proficiency level (idempotent).
     * </summary>
     *
     * @param User $user    User model instance
     * @param int  $skillId Target skill ID
     * @param int  $level   Proficiency level (1–5)
     * @return void
     */
    public function attachSkillToUser(User $user, int $skillId, int $level): void
    {
        $user->skills()->syncWithoutDetaching([$skillId => ['level' => $level]]);
    }

    /**
     * <summary>
     *  Update the proficiency level of an already-attached skill pivot.
     * </summary>
     *
     * @param User $user    User model instance
     * @param int  $skillId Target skill ID
     * @param int  $level   New proficiency level (1–5)
     * @return void
     */
    public function updateUserSkill(User $user, int $skillId, int $level): void
    {
        $user->skills()->updateExistingPivot($skillId, ['level' => $level]);
    }

    /**
     * <summary>
     *  Remove a skill from a user.
     * </summary>
     *
     * @param User $user    User model instance
     * @param int  $skillId Target skill ID
     * @return void
     */
    public function detachSkillFromUser(User $user, int $skillId): void
    {
        $user->skills()->detach($skillId);
    }

    /**
     * <summary>
     *  Fetch all users with their active-today absences, mapped to status rows.
     * </summary>
     *
     * @param string $today Date string (Y-m-d)
     * @return Collection Each item: id, name, role, initials, today_status
     */
    public function getTodayUsers(string $today): Collection
    {
        return User::query()
            ->with(['absences' => fn($q) => $q
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
            ])
            ->orderBy('firstname')
            ->get()
            ->map(fn($user) => [
                'id'           => $user->id,
                'name'         => $user->firstname . ' ' . $user->lastname,
                'role'         => $user->title,
                'initials'     => $this->deriveInitials($user->firstname . ' ' . $user->lastname),
                'today_status' => $this->resolveUserStatus($user)->value,
            ]);
    }

    /**
     * <summary>
     *  Compute the total employee count KPI stat.
     * </summary>
     *
     * @return array value, insight, severity
     */
    public function getTotalEmployeesStat(): array
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

    /**
     * <summary>
     *  Compute the department headcount balance KPI stat.
     * </summary>
     *
     * @return array value (Balanced|Skewed|Imbalanced), insight, severity
     */
    public function getDepartmentBalanceStat(): array
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

    private function resolveUserStatus(User $user): UserStatus
    {
        return $user->absences->isNotEmpty() ? UserStatus::Away : UserStatus::Available;
    }

    private function deriveInitials(string $name): string
    {
        $parts    = array_filter(explode(' ', trim($name)));
        $initials = array_map(fn($p) => strtoupper(mb_substr($p, 0, 1)), array_values($parts));

        return implode('', array_slice($initials, 0, 2));
    }
}
