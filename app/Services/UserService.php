<?php

namespace App\Services;

use App\Enums\UserStatus;
use App\Metrics\Calculators\CriticalityCalculator;
use App\Metrics\Scales\CriticalityScale;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricScope;
use App\Metrics\Snapshots\MetricSnapshotService;
use App\Metrics\Stat;
use App\Metrics\Scales\TeamAvailabilityScale;
use App\Models\Project;
use App\Models\SkillCategory;
use App\Models\User;
use App\Support\QueryParams;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class UserService
{
    public function __construct(
        private readonly CriticalityCalculator $criticalityCalculator,
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
     *  Retrieve users matching the given ids with their projects eager-loaded.
     *  Used by Managers that fan out per-project recalculation dispatch.
     * </summary>
     *
     * @param array<int> $userIds Ids of the users to load
     * @return Collection<int, User> Users with projects loaded
     */
    public function getUsersWithProjectsByIds(array $userIds): Collection
    {
        return User::query()->with('projects')->whereIn('id', $userIds)->get();
    }

    /**
     * <summary>
     *  Build a paginated, filterable, sortable query for users via Spatie QueryBuilder.
     *  Supports search (name/email), department, skill and status filters.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated users with department and skills.category
     */
    public function getAgileUsers(QueryParams $params): LengthAwarePaginator
    {
        return QueryBuilder::for(User::class, $params->toRequest())
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
                AllowedFilter::callback('not_in_project', function ($query, $value) {
                    $query->whereDoesntHave('projects', fn($q) => $q->where('projects.id', (int) $value));
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
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Build a paginated, filterable, sortable query for the users assigned to a project via Spatie QueryBuilder.
     *  Scoped to the project's team pivot. Supports search (name/email), department, skill and status filters.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter, sort & search parameters
     * @param Project $project Target project whose team is listed
     * @return LengthAwarePaginator Paginated project users with department and skills.category
     */
    public function getAgileUsersForProject(QueryParams $params, Project $project): LengthAwarePaginator
    {
        return QueryBuilder::for($project->users()->with(['department', 'skills.category']), $params->toRequest())
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

                    $today = now()->toDateString();
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
                AllowedSort::field('created_at', 'users.created_at'),
            ])
            ->defaultSort('firstname')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
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
     *  Eager-load the department relation on a User instance.
     * </summary>
     *
     * @param User $user Target user
     * @return User Same instance with department loaded
     */
    public function getUser(User $user): User
    {
        return $user->load('department');
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
     *  Count every user in the org. Single DB action.
     * </summary>
     *
     * @return int Total user count
     */
    public function countAllUsers(): int
    {
        return User::query()->count();
    }

    /**
     * <summary>
     *  Count users with at least one absence active on the given date. Single DB action.
     * </summary>
     *
     * @param string $today Date string (Y-m-d)
     * @return int Number of users absent on that date
     */
    public function countUsersAbsentOn(string $today): int
    {
        return User::query()
            ->whereHas('absences', fn($q) => $q
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today))
            ->count();
    }

    // ───────────────────────── /users/stats ─────────────────────────

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
     *  Latest org-snapshot for dashboard team-availability. Read API for GET /dashboard/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getTeamAvailabilityStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::DashboardTeamAvailability);
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
        return $this->criticalityCalculator->computeRawForUser($user);
    }

    /**
     * <summary>
     *  Build the recommendation list for a user based on the criticality breakdown
     *  (score, unique_skills, silo_count, bus_factor_projects). Severity-sorted.
     * </summary>
     *
     * @param User $user Target user
     * @return array<int, array{id:string,icon:string,title:string,description:string,severity:string,priority:string}>
     */
    public function getUserRecommendations(User $user): array
    {
        $breakdown = $this->criticalityCalculator->computeRawForUser($user);
        $score = (int) $breakdown['score'];
        $unique = (int) $breakdown['unique_skills'];
        $silo = (int) $breakdown['silo_count'];
        $busProjects = (int) $breakdown['bus_factor_projects'];

        $recs = [];

        if ($busProjects > 0) {
            $recs[] = [
                'id' => 'bus-factor-mitigate',
                'icon' => 'git-branch',
                'title' => "Mitigate bus-factor on {$busProjects} project" . ($busProjects > 1 ? 's' : ''),
                'description' => 'Assign a secondary owner to each affected project to ensure continuity if this employee is absent.',
                'severity' => Severity::CRITICAL->value,
                'priority' => 'high',
            ];
        }

        if ($unique > 0) {
            $recs[] = [
                'id' => 'unique-skills-transfer',
                'icon' => 'users',
                'title' => "{$unique} skill" . ($unique > 1 ? 's' : '') . ' held only by this employee',
                'description' => 'Schedule knowledge transfer sessions or pair-work to spread these skills across the team.',
                'severity' => Severity::CRITICAL->value,
                'priority' => 'high',
            ];
        }

        if ($busProjects === 0 && $silo > 0) {
            $recs[] = [
                'id' => 'silo-monitor',
                'icon' => 'alert-triangle',
                'title' => "Silo exposure on {$silo} project" . ($silo > 1 ? 's' : ''),
                'description' => 'Active on projects where this employee is the sole holder of a required skill. Monitor for escalation.',
                'severity' => Severity::WARNING->value,
                'priority' => 'medium',
            ];
        }

        if ($score >= 70) {
            $recs[] = [
                'id' => 'high-criticality-review',
                'icon' => 'shield-alert',
                'title' => 'Schedule a knowledge-transfer review this quarter',
                'description' => 'Composite criticality is high — convene a session with the team lead to plan redundancy.',
                'severity' => Severity::CRITICAL->value,
                'priority' => 'high',
            ];
        } elseif ($score >= 40 && $recs === []) {
            $recs[] = [
                'id' => 'criticality-drift',
                'icon' => 'alert-triangle',
                'title' => 'Monitor criticality drift',
                'description' => 'Score is in the medium band. Watch for new silos or project assignments that could escalate risk.',
                'severity' => Severity::WARNING->value,
                'priority' => 'medium',
            ];
        }

        if ($recs === []) {
            $recs[] = [
                'id' => 'no-risk',
                'icon' => 'lightbulb',
                'title' => 'No structural risk detected',
                'description' => 'Knowledge is well distributed. Keep monitoring as the team and skill graph evolve.',
                'severity' => Severity::OK->value,
                'priority' => 'low',
            ];
        }

        $severityRank = [
            Severity::CRITICAL->value => 0,
            Severity::WARNING->value => 1,
            Severity::OK->value => 2,
        ];
        usort($recs, fn($a, $b) => $severityRank[$a['severity']] <=> $severityRank[$b['severity']]);

        return $recs;
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

    /**
     * <summary>
     *  Competency radar for a user. One axis per SkillCategory in the DB (stable order by name).
     *  value = round(avg(level) / 5 * 100) over the user's EmployeeSkill rows belonging to the category.
     *  Categories the user has no skill in return 0. target is fixed at 80 for now. Read-only.
     * </summary>
     *
     * @param User $user Target user
     * @return array<int, array{category:string, value:int, target:int}>
     */
    public function getUserCompetencyRadar(User $user): array
    {
        $user->loadMissing('skills.category');

        $categories = SkillCategory::query()->orderBy('name')->get(['id', 'name']);

        $sums = [];
        $counts = [];
        foreach ($categories as $category) {
            $sums[$category->id] = 0;
            $counts[$category->id] = 0;
        }

        foreach ($user->skills as $skill) {
            $categoryId = $skill->skill_category_id;
            if (!isset($sums[$categoryId])) {
                continue;
            }
            $sums[$categoryId] += (int) $skill->pivot->level;
            $counts[$categoryId]++;
        }

        $target = 80;
        $result = [];
        foreach ($categories as $category) {
            $count = $counts[$category->id];
            $value = $count === 0 ? 0 : (int) round(($sums[$category->id] / $count) / 5 * 100);

            $result[] = [
                'category' => $category->name,
                'value' => $value,
                'target' => $target,
            ];
        }

        return $result;
    }
}
