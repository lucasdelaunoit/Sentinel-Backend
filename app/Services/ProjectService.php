<?php

namespace App\Services;

use App\Metrics\Scales\AbsenceImpactScale;
use App\Metrics\Scales\FragilityScale;
use App\Metrics\Scales\KnowledgeCoverageScale;
use App\Metrics\Scales\TeamAvailabilityScale;
use App\Metrics\Severity;
use App\Metrics\Snapshots\MetricKey;
use App\Metrics\Snapshots\MetricScope;
use App\Metrics\Snapshots\MetricSnapshotService;
use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Metrics\Stat;
use App\Models\Project;
use App\Models\Skill;
use App\Models\SkillCategory;
use App\Models\User;
use App\Support\CompetencyRadar;
use App\Support\QueryParams;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProjectService
{
    public function __construct(
        private readonly MetricSnapshotService $snapshotService,
    ) {}

    /**
     * <summary>
     *  Current org-wide metric baseline from cached project columns over non-archived projects:
     *  project count plus the average fragility_raw and knowledge_coverage_raw. Used to anchor
     *  estimated metric deltas (Upcoming Risk Events org impact). count is floored at 1 to avoid /0.
     * </summary>
     *
     * @return array{count:int, avg_fragility:float, avg_knowledge_coverage:float}
     */
    public function getActiveProjectsMetricBaseline(): array
    {
        $projects = Project::query()
            ->whereNull('archived_at')
            ->get(['fragility_raw', 'knowledge_coverage_raw']);

        return [
            'count' => max(1, $projects->count()),
            'avg_fragility' => (float) ($projects->avg('fragility_raw') ?? 0),
            'avg_knowledge_coverage' => (float) ($projects->avg('knowledge_coverage_raw') ?? 0),
        ];
    }

    /**
     * <summary>
     *  Retrieve every non-archived Project (id column only). Used by callers that need
     *  to fan out per-project work such as risk recalculation dispatch.
     * </summary>
     *
     * @return Collection<int, Project> Non-archived projects with only id loaded
     */
    public function getNonArchivedProjects(): Collection
    {
        return Project::query()->whereNull('archived_at')->get(['id']);
    }

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
     *  Latest org-snapshot for projects total. Read API for GET /projects/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getProjectsTotalStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::ProjectsTotal);
    }

    /**
     * <summary>
     *  Latest org-snapshot for projects avg fragility. Read API for GET /projects/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getProjectsAvgFragilityStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::ProjectsAvgFragility);
    }

    /**
     * <summary>
     *  Latest org-snapshot for fragile-projects count. Read API for GET /projects/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getProjectsFragileCountStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::ProjectsFragileCount);
    }

    /**
     * <summary>
     *  Latest org-snapshot for deadline pressure. Read API for GET /projects/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getProjectsDeadlinePressureStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::ProjectsDeadlinePressure);
    }

    // ───────────────────────── /projects/stats ─────────────────────────

    // ──────────────────── /projects/{project}/stats ────────────────────

    /**
     * <summary>
     *  Fragility Stat for one project — reads precomputed projects.fragility_raw.
     * </summary>
     *
     * @param Project $project Target project
     * @return Stat
     */
    public function getProjectFragilityStat(Project $project): Stat
    {
        $raw = (int) $project->fragility_raw;

        return Stat::fromScale(FragilityScale::fromRaw($raw), $raw, "Score: {$raw}/100");
    }

/**
     * <summary>
     *  Team-availability Stat for one project — reads precomputed projects.team_availability_raw (% available).
     * </summary>
     *
     * @param Project $project Target project
     * @return Stat
     */
    public function getProjectTeamAvailabilityStat(Project $project): Stat
    {
        $raw = (int) $project->team_availability_raw;

        return Stat::fromScale(TeamAvailabilityScale::fromRaw($raw), $raw, "{$raw}% available");
    }

    /**
     * <summary>
     *  Knowledge-coverage Stat for one project — reads precomputed projects.knowledge_coverage_raw (% safe).
     * </summary>
     *
     * @param Project $project Target project
     * @return Stat
     */
    public function getProjectKnowledgeCoverageStat(Project $project): Stat
    {
        $raw = (int) $project->knowledge_coverage_raw;

        return Stat::display("{$raw}%", $raw, KnowledgeCoverageScale::fromRaw($raw), "{$raw}% safe");
    }

    /**
     * <summary>
     *  Deadline-countdown Stat for one project. Days remaining until deadline.
     *  Severity ladder: overdue/&lt;=7d critical, &lt;=30d warning, else ok.
     *  Completed projects return a frozen "Completed" Stat. Missing deadline returns "No deadline".
     * </summary>
     *
     * @param Project $project Target project
     * @return Stat
     */
    public function getProjectDeadlineCountdownStat(Project $project): Stat
    {
        if ($project->completed_at !== null) {
            return new Stat('Completed', 0, Severity::OK, 'Delivered');
        }

        if ($project->deadline === null) {
            return new Stat('No deadline', 0, Severity::OK, 'Untimed');
        }

        $days = (int) round(now()->startOfDay()->diffInDays($project->deadline->startOfDay(), false));

        if ($days < 0) {
            $overdue = abs($days);
            return new Stat(
                value: 'Overdue',
                valueRaw: $days,
                severity: Severity::CRITICAL,
                insight: "{$overdue} day" . ($overdue > 1 ? 's' : '') . ' past deadline',
            );
        }

        $severity = match (true) {
            $days <= 7 => Severity::CRITICAL,
            $days <= 30 => Severity::WARNING,
            default => Severity::OK,
        };

        $insight = match (true) {
            $days === 0 => 'Due today',
            $days <= 7 => 'Crunch time',
            $days <= 30 => 'Closing in',
            default => 'On schedule',
        };

        return new Stat(
            value: $days === 0 ? 'Today' : "{$days} day" . ($days > 1 ? 's' : ''),
            valueRaw: $days,
            severity: $severity,
            insight: $insight,
        );
    }

    // ───────────────────────── /dashboard/stats ─────────────────────────


    /**
     * <summary>
     *  Latest org-snapshot for dashboard worst-fragility. Read API for GET /dashboard/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getWorstFragilityStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::DashboardWorstFragility);
    }

    /**
     * <summary>
     *  Latest org-snapshot for dashboard knowledge-coverage. Read API for GET /dashboard/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getKnowledgeCoverageStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::DashboardKnowledgeCoverage);
    }

    /**
     * <summary>
     *  Latest org-snapshot for dashboard absence-impact. Read API for GET /dashboard/stats.
     * </summary>
     *
     * @return Stat
     */
    public function getAbsenceImpactStat(): Stat
    {
        return $this->readOrgSnapshotStat(MetricKey::DashboardAbsenceImpact);
    }

    /**
     * <summary>
     *  Retrieve all projects (paginated, filterable, sortable) with user count.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @return LengthAwarePaginator Paginated list of projects
     */
    public function getAgileProjects(QueryParams $params): LengthAwarePaginator
    {
        return QueryBuilder::for(Project::class, $params->toRequest())
            ->withCount('users')
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('name', 'like', "%{$v}%")),
                AllowedFilter::callback('status', function ($q, $v) {
                    $status = ProjectStatus::tryFrom((string) $v);
                    if ($status !== null) $q->whereStatus($status);
                }),
                AllowedFilter::callback('not_in_user', function ($query, $value) {
                    $query->whereDoesntHave('users', fn($q) => $q->where('users.id', (int) $value));
                }),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::callback('status', fn($q, bool $descending) => $q->orderByStatus($descending)),
                AllowedSort::callback('progress', fn($q, bool $descending) => $q->orderByProgress($descending)),
                AllowedSort::field('fragility_raw'),
                AllowedSort::field('risk_score', 'fragility_raw'),
                AllowedSort::field('team_availability', 'team_availability_raw'),
                AllowedSort::field('knowledge_coverage', 'knowledge_coverage_raw'),
                AllowedSort::field('absence_impact', 'absence_impact_raw'),
                AllowedSort::field('bus_factor'),
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('-created_at')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Build a paginated, filterable, sortable query for projects assigned to a user via Spatie QueryBuilder.
     *  Scoped to the user's projects pivot. Supports search (name), status filter and standard project sorts.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter, sort & search parameters
     * @param User $user Target user whose projects are listed
     * @return LengthAwarePaginator Paginated user projects with user count
     */
    public function getAgileProjectsForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return QueryBuilder::for($user->projects(), $params->toRequest())
            ->withCount('users')
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('name', 'like', "%{$v}%")),
                AllowedFilter::callback('status', function ($q, $v) {
                    $status = ProjectStatus::tryFrom((string) $v);
                    if ($status !== null) $q->whereStatus($status);
                }),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::callback('status', fn($q, bool $descending) => $q->orderByStatus($descending)),
                AllowedSort::callback('progress', fn($q, bool $descending) => $q->orderByProgress($descending)),
                AllowedSort::field('fragility_raw'),
                AllowedSort::field('risk_score', 'fragility_raw'),
                AllowedSort::field('team_availability', 'team_availability_raw'),
                AllowedSort::field('knowledge_coverage', 'knowledge_coverage_raw'),
                AllowedSort::field('absence_impact', 'absence_impact_raw'),
                AllowedSort::field('bus_factor'),
                AllowedSort::field('created_at', 'projects.created_at'),
            ])
            ->defaultSort('-created_at')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    /**
     * <summary>
     *  Persist a new project row.
     * </summary>
     *
     * @param array $data Validated fields (name, description, started_at, deadline)
     * @return Project Newly created project
     */
    public function createProject(array $data): Project
    {
        return Project::create($data);
    }

    /**
     * <summary>
     *  Attach a batch of users to a project pivot in one query (idempotent).
     * </summary>
     *
     * @param Project $project Target project
     * @param int[] $userIds User ids to attach
     * @return void
     */
    public function attachUsersToProject(Project $project, array $userIds): void
    {
        if ($userIds === []) return;

        $project->users()->syncWithoutDetaching($userIds);
    }

    /**
     * <summary>
     *  Attach a batch of skill requirements to a project pivot in one query (idempotent).
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int, array{skill_id:int, required_level:int}> $requirements List of skill requirements
     * @return void
     */
    public function attachSkillsToProject(Project $project, array $requirements): void
    {
        if ($requirements === []) return;

        $payload = collect($requirements)
            ->mapWithKeys(fn(array $r) => [
                (int) $r['skill_id'] => ['required_level' => (int) $r['required_level']],
            ])
            ->all();

        $project->skillRequirements()->syncWithoutDetaching($payload);
    }

    /**
     * <summary>
     *  Eager-load the relations needed for the detail view of a project.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Project with users.department and skillRequirements loaded
     */
    public function getProject(Project $project): Project
    {
        return $project->loadMissing([
            'users.department',
            'skillRequirements',
        ]);
    }

    /**
     * <summary>
     *  Apply field updates to an existing project and return the refreshed model.
     * </summary>
     *
     * @param Project $project Target project
     * @param array $data Validated fields to update
     * @return Project Refreshed project
     */
    public function updateProject(Project $project, array $data): Project
    {
        $project->update($data);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Delete a single project row. Does not touch related pivots.
     * </summary>
     *
     * @param Project $project Target project
     * @return void
     */
    public function deleteProject(Project $project): void
    {
        $project->delete();
    }

    /**
     * <summary>
     *  Attach a user to a project pivot (idempotent).
     * </summary>
     *
     * @param Project $project Target project
     * @param int $userId User id to attach
     * @return void
     */
    public function attachUserToProject(Project $project, int $userId): void
    {
        $project->users()->syncWithoutDetaching([$userId]);
    }

    /**
     * <summary>
     *  Detach a user from a project pivot.
     * </summary>
     *
     * @param Project $project Target project
     * @param int $userId User id to detach
     * @return void
     */
    public function detachUserFromProject(Project $project, int $userId): void
    {
        $project->users()->detach($userId);
    }

    /**
     * <summary>
     *  Attach a skill requirement to a project at the given required level (idempotent).
     * </summary>
     *
     * @param Project $project Target project
     * @param int $skillId Skill id to require
     * @param int $requiredLevel Required level (1–5)
     * @return void
     */
    public function attachSkillToProject(Project $project, int $skillId, int $requiredLevel): void
    {
        $project->skillRequirements()->syncWithoutDetaching([
            $skillId => ['required_level' => $requiredLevel],
        ]);
    }

    /**
     * <summary>
     *  Detach a skill requirement from a project.
     * </summary>
     *
     * @param Project $project Target project
     * @param int $skillId Skill id to detach
     * @return void
     */
    public function detachSkillFromProject(Project $project, int $skillId): void
    {
        $project->skillRequirements()->detach($skillId);
    }

    /**
     * <summary>
     *  Mark project as paused by setting paused_at to now.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     */
    public function pauseProject(Project $project): Project
    {
        $project->update(['paused_at' => now()]);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Resume a paused project by clearing paused_at.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     */
    public function resumeProject(Project $project): Project
    {
        $project->update(['paused_at' => null]);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Mark project as completed (sets completed_at, clears paused_at).
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     */
    public function completeProject(Project $project): Project
    {
        $project->update([
            'completed_at' => now(),
            'paused_at' => null,
        ]);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Reopen a completed project by clearing completed_at.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     */
    public function reopenProject(Project $project): Project
    {
        $project->update(['completed_at' => null]);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Archive a project by setting archived_at to now.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     */
    public function archiveProject(Project $project): Project
    {
        $project->update(['archived_at' => now()]);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Unarchive a project by clearing archived_at.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     */
    public function unarchiveProject(Project $project): Project
    {
        $project->update(['archived_at' => null]);

        return $project->fresh();
    }

    /**
     * <summary>
     *  Correlated scalar subquery counting ACTIVE holders of the current skill row: project members
     *  who hold the skill at level >= psr.required_level and whose Absence range does not cover today.
     *  References skills.id and psr.required_level from the outer query, so it only works inside the
     *  knowledge-coverage base query. Placeholders bind to [project_id, today, today].
     * </summary>
     */
    private function activeHoldersCountSql(): string
    {
        return "(select count(*) from project_users pu
                   where pu.project_id = ?
                     and exists (
                       select 1 from user_skills us
                       where us.user_id = pu.user_id
                         and us.skill_id = skills.id
                         and us.level >= psr.required_level
                     )
                     and not exists (
                       select 1 from absences ab
                       where ab.user_id = pu.user_id
                         and ab.start_date <= ?
                         and ab.end_date >= ?
                     ))";
    }

    /**
     * <summary>
     *  Base query for a project's knowledge coverage: one row per required skill, enriched with
     *  computed columns active_holders_count (level>=required, not on leave today), max_level
     *  (best level any project member holds) and holders_total (members holding the skill at any
     *  level). Drives the paginated list, the status filter and the summary aggregate.
     * </summary>
     */
    private function knowledgeCoverageBaseQuery(Project $project): Builder
    {
        $pid = $project->getKey();
        $today = Carbon::today()->toDateString();

        $maxLevelSql = "(select coalesce(max(us.level), 0) from user_skills us
                           join project_users pu on pu.user_id = us.user_id and pu.project_id = ?
                           where us.skill_id = skills.id)";

        $holdersTotalSql = "(select count(*) from project_users pu
                               join user_skills us on us.user_id = pu.user_id and us.skill_id = skills.id
                               where pu.project_id = ?)";

        return Skill::query()
            ->join('project_skill_reqs as psr', 'psr.skill_id', '=', 'skills.id')
            ->where('psr.project_id', $pid)
            ->with('category')
            ->select('skills.*', 'psr.required_level')
            ->selectRaw($this->activeHoldersCountSql() . ' as active_holders_count', [$pid, $today, $today])
            ->selectRaw($maxLevelSql . ' as max_level', [$pid])
            ->selectRaw($holdersTotalSql . ' as holders_total', [$pid]);
    }

    /**
     * <summary>
     *  Public access to the full (unpaginated) knowledge-coverage matrix with complete holder lists.
     *  For dashboard cards that aggregate across every required skill (today snapshot, current absence
     *  impact), where the paginated list endpoint and its 5-holder cap would be incorrect.
     * </summary>
     *
     * @param Project $project Target project
     * @return array Full knowledge-coverage rows
     */
    public function getProjectKnowledgeMatrix(Project $project): array
    {
        return $this->knowledgeCoverageMatrix($project);
    }

    private function knowledgeCoverageMatrix(Project $project): array
    {
        $project->loadMissing([
            'skillRequirements.category',
            'users.skills',
            'users.absences',
        ]);

        $today = Carbon::today();
        $teamSize = $project->users->count();
        $rows = [];

        foreach ($project->skillRequirements as $skill) {
            $required = (int) ($skill->pivot->required_level ?? 1);
            $holders = [];
            $maxLevel = 0;
            $activeCount = 0;

            foreach ($project->users as $user) {
                $userSkill = $user->skills->firstWhere('id', $skill->id);
                if ($userSkill === null) {
                    continue;
                }

                $level = (int) $userSkill->pivot->level;
                $maxLevel = max($maxLevel, $level);

                $onLeave = $user->absences->contains(function ($a) use ($today) {
                    return Carbon::parse($a->start_date)->lte($today)
                        && Carbon::parse($a->end_date)->gte($today);
                });

                if (!$onLeave && $level >= $required) {
                    $activeCount++;
                }

                $holders[] = [
                    'id' => $user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'status' => ($onLeave ? UserStatus::Away : UserStatus::Available)->value,
                    'level' => $level,
                    'on_leave_today' => $onLeave,
                ];
            }

            $status = match (true) {
                $activeCount === 0 => 'uncovered',
                $activeCount === 1 => 'silo',
                default => 'covered',
            };

            $rows[] = [
                'skill' => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                    'category' => $skill->category?->name,
                ],
                'required_level' => $required,
                'max_level' => $maxLevel,
                'active_holders_count' => $activeCount,
                'team_size' => $teamSize,
                'status' => $status,
                'holders' => $holders,
            ];
        }

        return $rows;
    }

    /**
     * <summary>
     *  Paginated, searchable, sortable, filterable knowledge-coverage breakdown for a project.
     *  Search matches skill name; filter[category_id] is exact; filter[status] (uncovered/silo/covered)
     *  filters on the computed active_holders_count. Sortable by name, required_level, active_holders_count,
     *  max_level and status. Each page row carries its first 5 holders (best level first) plus holders_total
     *  so the UI can offer "view all". Read-only.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @param Project $project Target project
     * @return LengthAwarePaginator Paginated coverage rows
     */
    public function getProjectKnowledgeCoverage(QueryParams $params, Project $project): LengthAwarePaginator
    {
        $pid = $project->getKey();
        $today = Carbon::today()->toDateString();
        $statusSql = $this->activeHoldersCountSql();

        $nameSort = AllowedSort::callback('name', fn($q, bool $desc) => $q->orderBy('skills.name', $desc ? 'desc' : 'asc'));

        $paginator = QueryBuilder::for($this->knowledgeCoverageBaseQuery($project), $params->toRequest())
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('skills.name', 'like', "%{$v}%")),
                AllowedFilter::callback('category_id', fn($q, $v) => $q->where('skills.skill_category_id', $v)),
                AllowedFilter::callback('status', function ($q, $v) use ($statusSql, $pid, $today) {
                    match ($v) {
                        'uncovered' => $q->whereRaw("({$statusSql}) = 0", [$pid, $today, $today]),
                        'silo' => $q->whereRaw("({$statusSql}) = 1", [$pid, $today, $today]),
                        'covered' => $q->whereRaw("({$statusSql}) >= 2", [$pid, $today, $today]),
                        default => null,
                    };
                }),
            ])
            ->allowedSorts([
                $nameSort,
                AllowedSort::callback('required_level', fn($q, bool $desc) => $q->orderBy('psr.required_level', $desc ? 'desc' : 'asc')),
                AllowedSort::callback('active_holders_count', fn($q, bool $desc) => $q->orderBy('active_holders_count', $desc ? 'desc' : 'asc')),
                AllowedSort::callback('max_level', fn($q, bool $desc) => $q->orderBy('max_level', $desc ? 'desc' : 'asc')),
                AllowedSort::callback('status', fn($q, bool $desc) => $q->orderBy('active_holders_count', $desc ? 'desc' : 'asc')),
            ])
            ->defaultSort($nameSort)
            ->paginate($params->perPage())
            ->appends($params->rawQuery());

        return $this->attachKnowledgeCoverageHolders($project, $paginator);
    }

    /**
     * <summary>
     *  Transforms each paginated Skill row into the coverage payload, attaching the first 5 holders
     *  (best level first) computed in PHP against the small project team. Mutates the paginator's
     *  collection in place and returns it.
     * </summary>
     */
    private function attachKnowledgeCoverageHolders(Project $project, LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        $project->loadMissing(['users.skills', 'users.absences']);
        $today = Carbon::today();
        $teamSize = $project->users->count();

        $paginator->getCollection()->transform(function ($skill) use ($project, $today, $teamSize) {
            $holders = [];

            foreach ($project->users as $user) {
                $userSkill = $user->skills->firstWhere('id', $skill->id);
                if ($userSkill === null) {
                    continue;
                }

                $onLeave = $user->absences->contains(function ($a) use ($today) {
                    return Carbon::parse($a->start_date)->lte($today)
                        && Carbon::parse($a->end_date)->gte($today);
                });

                $holders[] = [
                    'id' => $user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'status' => ($onLeave ? UserStatus::Away : UserStatus::Available)->value,
                    'level' => (int) $userSkill->pivot->level,
                    'on_leave_today' => $onLeave,
                ];
            }

            usort($holders, fn($a, $b) => $b['level'] <=> $a['level']);

            $activeCount = (int) $skill->active_holders_count;
            $status = match (true) {
                $activeCount === 0 => 'uncovered',
                $activeCount === 1 => 'silo',
                default => 'covered',
            };

            return [
                'id' => $skill->id,
                'skill' => [
                    'id' => $skill->id,
                    'name' => $skill->name,
                    'category' => $skill->category?->name,
                ],
                'required_level' => (int) $skill->required_level,
                'max_level' => (int) $skill->max_level,
                'active_holders_count' => $activeCount,
                'team_size' => $teamSize,
                'status' => $status,
                'holders' => array_slice($holders, 0, 5),
                'holders_total' => (int) $skill->holders_total,
            ];
        });

        return $paginator;
    }

    /**
     * <summary>
     *  Project-wide coverage summary: counts of required skills that are covered (>=2 active holders),
     *  silo (1) and uncovered (0), plus the total. Computed over ALL required skills, independent of
     *  pagination, so the dashboard totals stay exact regardless of which list page is shown. Read-only.
     * </summary>
     *
     * @param Project $project Target project
     * @return array{covered:int, silo:int, uncovered:int, total:int}
     */
    public function getProjectKnowledgeCoverageSummary(Project $project): array
    {
        $rows = $this->knowledgeCoverageBaseQuery($project)->get();

        $covered = 0;
        $silo = 0;
        $uncovered = 0;

        foreach ($rows as $row) {
            $active = (int) $row->active_holders_count;
            if ($active === 0) {
                $uncovered++;
            } elseif ($active === 1) {
                $silo++;
            } else {
                $covered++;
            }
        }

        return [
            'covered' => $covered,
            'silo' => $silo,
            'uncovered' => $uncovered,
            'total' => $rows->count(),
        ];
    }

    /**
     * <summary>
     *  Paginated holders of a single skill within a project — every team member who holds the skill at
     *  any level, with their level and today's leave status. Backs the "view all holders" modal opened
     *  from a coverage row. Search matches name/email; sortable by name. Read-only.
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @param Project $project Target project
     * @param Skill $skill Target skill
     * @return LengthAwarePaginator Paginated holder rows
     */
    public function getProjectSkillHolders(QueryParams $params, Project $project, Skill $skill): LengthAwarePaginator
    {
        $today = Carbon::today();

        $base = $project->users()
            ->whereHas('skills', fn($q) => $q->where('skills.id', $skill->id))
            ->with([
                'skills' => fn($q) => $q->where('skills.id', $skill->id),
                'absences',
            ]);

        $nameSort = AllowedSort::callback('name', function ($query, bool $desc) {
            $dir = $desc ? 'desc' : 'asc';
            $query->orderBy('firstname', $dir)->orderBy('lastname', $dir);
        });

        $paginator = QueryBuilder::for($base, $params->toRequest())
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where(fn($q) => $q
                        ->where('firstname', 'like', "%{$value}%")
                        ->orWhere('lastname', 'like', "%{$value}%")
                        ->orWhere('email', 'like', "%{$value}%"));
                }),
            ])
            ->allowedSorts([$nameSort])
            ->defaultSort($nameSort)
            ->paginate($params->perPage())
            ->appends($params->rawQuery());

        $paginator->getCollection()->transform(function ($user) use ($today) {
            $userSkill = $user->skills->first();

            $onLeave = $user->absences->contains(function ($a) use ($today) {
                return Carbon::parse($a->start_date)->lte($today)
                    && Carbon::parse($a->end_date)->gte($today);
            });

            return [
                'id' => $user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'status' => ($onLeave ? UserStatus::Away : UserStatus::Available)->value,
                'level' => (int) ($userSkill?->pivot->level ?? 0),
                'on_leave_today' => $onLeave,
            ];
        });

        return $paginator;
    }

    /**
     * <summary>
     *  Fragility-alert feed for a project. Derives a prioritized list of decision-support alerts
     *  purely from the knowledge-coverage matrix and the project's cached state — bus factor,
     *  active absences and the skills they expose, knowledge silos, uncovered skills, fragility
     *  trajectory and an overdue deadline. Read-only. Each alert is
     *  { id, severity (critical|warning|info), category, title, detail }.
     * </summary>
     *
     * @param Project $project Target project
     * @return array<int, array{id:string, severity:string, category:string, title:string, detail:string}>
     */
    public function getProjectFragilityAlerts(Project $project): array
    {
        $coverage = $this->knowledgeCoverageMatrix($project);
        $alerts = [];

        // Bus factor
        $busFactor = (int) $project->bus_factor;
        if ($busFactor <= 1) {
            $silos = count(array_filter($coverage, fn($c) => $c['status'] === 'silo'));
            $alerts[] = [
                'id' => 'bus-factor',
                'severity' => 'critical',
                'category' => 'Bus Factor',
                'title' => 'Project has a Bus Factor of 1',
                'detail' => $silos . ' skill' . ($silos === 1 ? ' has' : 's have')
                    . ' only one active owner. Losing that person would immediately block the project.',
            ];
        } elseif ($busFactor === 2) {
            $alerts[] = [
                'id' => 'bus-factor',
                'severity' => 'warning',
                'category' => 'Bus Factor',
                'title' => 'Bus Factor is low (2)',
                'detail' => 'Two absences could put the project at serious risk. Consider cross-training.',
            ];
        }

        // Members currently on leave and the skills their absence exposes
        $onLeave = [];
        foreach ($coverage as $row) {
            foreach ($row['holders'] as $holder) {
                if (!$holder['on_leave_today']) {
                    continue;
                }
                $id = $holder['id'];
                $onLeave[$id] ??= [
                    'name' => trim("{$holder['firstname']} {$holder['lastname']}"),
                    'uncovered' => [],
                    'silo' => [],
                ];
                if ($row['status'] === 'uncovered') {
                    $onLeave[$id]['uncovered'][] = $row['skill']['name'];
                } elseif ($row['status'] === 'silo') {
                    $onLeave[$id]['silo'][] = $row['skill']['name'];
                }
            }
        }
        foreach ($onLeave as $id => $member) {
            $hasUncovered = $member['uncovered'] !== [];
            $alerts[] = [
                'id' => "leave-{$id}",
                'severity' => $hasUncovered ? 'critical' : 'warning',
                'category' => 'Active Absence',
                'title' => "{$member['name']} is currently on leave",
                'detail' => $hasUncovered
                    ? implode(', ', $member['uncovered']) . ' now has zero active coverage.'
                    : ($member['silo'] !== []
                        ? implode(', ', $member['silo']) . ' dropped to a single active holder.'
                        : 'All required skills remain covered by other team members.'),
            ];
        }

        // Knowledge silos (single active holder)
        foreach ($coverage as $row) {
            if ($row['status'] !== 'silo') {
                continue;
            }
            $alerts[] = [
                'id' => "silo-{$row['skill']['id']}",
                'severity' => 'warning',
                'category' => 'Knowledge Silo',
                'title' => "\"{$row['skill']['name']}\" relies on a single person",
                'detail' => "Only one active team member covers {$row['skill']['name']}. "
                    . 'Their absence would leave the project without coverage.',
            ];
        }

        // Uncovered required skills (no active holder)
        foreach ($coverage as $row) {
            if ($row['status'] !== 'uncovered') {
                continue;
            }
            $alerts[] = [
                'id' => "uncovered-{$row['skill']['id']}",
                'severity' => 'critical',
                'category' => 'Uncovered Skill',
                'title' => "\"{$row['skill']['name']}\" is not actively covered",
                'detail' => 'This required skill has no available holder on the team. Assign someone or recruit.',
            ];
        }

        // Fragility trajectory (health = 100 - fragility_raw)
        $health = 100 - (int) $project->fragility_raw;
        if ($health < 50) {
            $alerts[] = [
                'id' => 'trajectory',
                'severity' => 'critical',
                'category' => 'Project Trajectory',
                'title' => "Project trajectory is critical ({$health}/100)",
                'detail' => 'Multiple risk factors are combining. Immediate manager intervention recommended.',
            ];
        } elseif ($health < 65) {
            $alerts[] = [
                'id' => 'trajectory',
                'severity' => 'warning',
                'category' => 'Project Trajectory',
                'title' => "Project trajectory is degraded ({$health}/100)",
                'detail' => 'Risk factors are accumulating. Monitor closely and address knowledge silos.',
            ];
        }

        // Overdue deadline
        if (
            $project->completed_at === null
            && $project->deadline !== null
            && $project->deadline->startOfDay()->lt(Carbon::today())
        ) {
            $alerts[] = [
                'id' => 'overdue',
                'severity' => 'critical',
                'category' => 'Deadline',
                'title' => 'Project is past its deadline',
                'detail' => 'Deadline was ' . $project->deadline->format('d M Y') . '. Delivery risk is high.',
            ];
        }

        return $alerts;
    }

    /**
     * <summary>
     *  Competency radar for a project. One axis per SkillCategory in the DB (stable order by name).
     *  value = round(avg(level) / 5 * 100) over all EmployeeSkill rows of the team where the skill
     *  belongs to the category. Categories with no held skill in the team return 0. target is fixed
     *  at 80 for now (no setting wired yet). Read-only.
     * </summary>
     *
     * @param Project $project Target project
     * @return array<int, array{category:string, value:int, target:int}>
     */
    public function getProjectCompetencyRadar(Project $project): array
    {
        $project->loadMissing(['users.skills.category']);

        $categories = SkillCategory::query()->orderBy('name')->get(['id', 'name']);
        $skills = $project->users->flatMap(fn(User $user) => $user->skills);

        return CompetencyRadar::build($categories, $skills);
    }
}
