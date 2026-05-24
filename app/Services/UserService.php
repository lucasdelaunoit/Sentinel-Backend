<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Metrics\Scales\CriticalityScale;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricScope;
use App\Metrics\Snapshots\MetricSnapshotService;
use App\Metrics\Stat;
use App\Metrics\Scales\TeamAvailabilityScale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class UserService
{
    public function __construct(
        private readonly RiskCalculationService $riskService,
        private readonly MetricSnapshotService $snapshotService,
    ) {}

    /**
     * <summary>
     *  Read the latest org-scope snapshot for the given metric key and rehydrate it as a Stat.
     *  Returns a placeholder Stat when no snapshot has been captured yet.
     * </summary>
     *
     * @param MetricKey $metric Snapshot key to read
     * @return Stat
     */
    private function readOrgSnapshotStat(MetricKey $metric): Stat
    {
        $snap = $this->snapshotService->latestFor(MetricScope::Org, null, $metric);

        return $snap !== null ? Stat::fromSnapshot($snap) : Stat::placeholder();
    }

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
                'id' => $user->id,
                'name' => $user->firstname . ' ' . $user->lastname,
                'role' => $user->title,
                'initials' => $this->deriveInitials($user->firstname . ' ' . $user->lastname),
                'today_status' => $this->resolveUserStatus($user)->value,
            ]);
    }

    // ───────────────────────── /users/stats ─────────────────────────

    /**
     * <summary>
     *  Total users count Stat — fresh SQL compute. Used by snapshot writer.
     * </summary>
     *
     * @return Stat
     */
    public function computeUsersTotalStat(): Stat
    {
        $total = User::count();

        return new Stat(
            value: "{$total} " . ($total === 1 ? 'user' : 'users'),
            valueRaw: $total,
            severity: Severity::OK,
            insight: 'Headcount',
        );
    }

    /**
     * <summary>
     *  Available-today Stat — total minus users absent today. WARNING when anyone away.
     * </summary>
     *
     * @return Stat
     */
    public function computeUsersAvailableStat(): Stat
    {
        $today = now()->toDateString();
        $total = User::count();
        $away = User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->count();
        $available = $total - $away;

        return new Stat(
            value: "{$available} available",
            valueRaw: $available,
            severity: $away > 0 ? Severity::WARNING : Severity::OK,
            insight: $away > 0 ? "{$away} away today" : 'All present',
        );
    }

    /**
     * <summary>
     *  Critical-users Stat — count of users with cached criticality_raw &gt;= 50.
     *  Reads the precomputed column rather than re-walking the matrix per user.
     * </summary>
     *
     * @return Stat
     */
    public function computeUsersCriticalStat(): Stat
    {
        $count = User::query()->where('criticality_raw', '>=', 50)->count();

        return new Stat(
            value: $count === 0 ? 'Safe' : "{$count} at-risk",
            valueRaw: $count,
            severity: $count > 0 ? Severity::CRITICAL : Severity::OK,
            insight: 'Criticality ≥ 50',
        );
    }

    /**
     * <summary>
     *  Unique skill holders Stat — count of users who hold at least one skill no one else holds org-wide.
     * </summary>
     *
     * @return Stat
     */
    public function computeUsersUniqueSkillHoldersStat(): Stat
    {
        $count = User::query()
            ->whereHas('skills', function ($q) {
                $q->whereIn('skills.id', function ($sub) {
                    $sub->from('user_skills')
                        ->select('skill_id')
                        ->groupBy('skill_id')
                        ->havingRaw('count(distinct user_id) = 1');
                });
            })
            ->count();

        return new Stat(
            value: "{$count} " . ($count === 1 ? 'sole holder' : 'sole holders'),
            valueRaw: $count,
            severity: $count > 0 ? Severity::WARNING : Severity::OK,
            insight: 'Skill held by one user',
        );
    }

    /**
     * <summary>
     *  Latest org-snapshot for users total. Read API for GET /users/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getUsersTotalStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::UsersTotal);
    }

    /**
     * <summary>
     *  Latest org-snapshot for users available today. Read API for GET /users/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getUsersAvailableStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::UsersAvailable);
    }

    /**
     * <summary>
     *  Latest org-snapshot for critical-users count. Read API for GET /users/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getCriticalUsersStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::UsersCritical);
    }

    /**
     * <summary>
     *  Latest org-snapshot for unique-skill-holders count. Read API for GET /users/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getUniqueSkillHoldersStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::UsersUniqueSkillHolders);
    }

    // ─────────────────────── /dashboard/stats ───────────────────────

    /**
     * <summary>
     *  Return the ids of users absent today. Used by Dashboard absence-impact Stat
     *  and any caller needing the live absent roster.
     * </summary>
     *
     * @return array<int> User ids
     */
    public function getAbsentUserIdsToday(): array
    {
        $today = now()->toDateString();

        return User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();
    }

    /**
     * <summary>
     *  Team-availability Stat for the dashboard — today's available/total headcount.
     *  critical_absent counts distinct absent users whose active project has bus_factor &lt;= 1.
     * </summary>
     *
     * @return Stat
     */
    public function getTeamAvailabilityStat(): Stat
    {
        $today = now()->toDateString();
        $total = User::count();

        $absent = User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->count();

        $available = $total - $absent;
        $pct = $total > 0 ? (int) round(($available / $total) * 100) : 100;

        $insight = $absent > 0
            ? "{$absent} employee" . ($absent > 1 ? 's' : '') . ' absent'
            : 'Fully operational';

        return Stat::display(
            "{$available}/{$total}",
            $pct,
            TeamAvailabilityScale::fromRaw($pct),
            $insight,
        );
    }

    /**
     * <summary>
     *  Return the full criticality breakdown for a user (raw score + silo_count + bus_factor_contributions).
     *  Used by GET /users/{user}/criticality. Stat-shaped form is built by getUserCriticalityStat.
     * </summary>
     *
     * @param User $user Target user
     * @return array Criticality breakdown
     */
    public function getUserCriticality(User $user): array
    {
        return $this->riskService->computeUserCriticality($user);
    }

    /**
     * <summary>
     *  Build the criticality Stat for a user — reads precomputed users.criticality_raw.
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserCriticalityStat(User $user): Stat
    {
        $score = (int) $user->criticality_raw;

        return Stat::fromScale(
            CriticalityScale::fromRaw($score),
            $score,
            "Score: {$score}/100",
        );
    }

    /**
     * <summary>
     *  Build the bus-factor-in-org Stat for a user — reads precomputed users.bus_factor_in_org_raw.
     *  Value = count of active projects where the user pushes bus factor &lt;= 2.
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserBusFactorInOrgStat(User $user): Stat
    {
        $count = (int) $user->bus_factor_in_org_raw;

        return new Stat(
            value: $count === 0 ? 'Safe' : (string) $count,
            valueRaw: $count,
            severity: $count > 0 ? Severity::CRITICAL : Severity::OK,
            insight: $count > 0
                ? "{$count} project" . ($count > 1 ? 's' : '') . ' at risk'
                : 'No single-point exposure',
        );
    }

    /**
     * <summary>
     *  Build the skills-count Stat for a user — live count via skills relation. Insight = distinct category count.
     *  Not cached: query is trivial (single indexed pivot count).
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserSkillsStat(User $user): Stat
    {
        $user->loadMissing('skills.category');
        $total = $user->skills->count();
        $catCount = $user->skills
            ->groupBy(fn($s) => $s->category?->name ?? 'Uncategorized')
            ->count();

        return new Stat(
            value: $total === 0 ? 'None' : (string) $total,
            valueRaw: $total,
            severity: Severity::OK,
            insight: $catCount > 0 ? "{$catCount} " . ($catCount === 1 ? 'category' : 'categories') : null,
        );
    }

    /**
     * <summary>
     *  Build the active-projects-count Stat for a user — live count via projects relation filtered to active lifecycle.
     *  Not cached: query is lightweight (indexed lifecycle cols).
     * </summary>
     *
     * @param User $user Target user
     * @return Stat
     */
    public function getUserActiveProjectsStat(User $user): Stat
    {
        $count = $user->projects()
            ->whereNotNull('started_at')
            ->whereDate('started_at', '<=', now())
            ->whereNull('paused_at')
            ->whereNull('completed_at')
            ->whereNull('archived_at')
            ->count();

        return new Stat(
            value: $count === 0 ? 'None' : (string) $count,
            valueRaw: $count,
            severity: Severity::OK,
            insight: $count > 0 ? 'Assigned' : null,
        );
    }

    private function resolveUserStatus(User $user): UserStatus
    {
        return $user->absences->isNotEmpty() ? UserStatus::Away : UserStatus::Available;
    }

    private function deriveInitials(string $name): string
    {
        $parts = array_filter(explode(' ', trim($name)));
        $initials = array_map(fn($p) => strtoupper(mb_substr($p, 0, 1)), array_values($parts));

        return implode('', array_slice($initials, 0, 2));
    }
}
