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
use App\Models\SkillCategory;
use App\Models\User;
use App\Support\QueryParams;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProjectService
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
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
     *  Knowledge-coverage breakdown for a project. Per project skill requirement, lists
     *  team members who hold that skill at level >= required_level. active_holders excludes
     *  members whose Absence range covers today. status = uncovered (0 active), silo (1),
     *  covered (>=2). max_level = max level across all holders regardless of level. Read-only.
     * </summary>
     *
     * @param Project $project Target project
     * @return array<int, array{
     *     skill: array{id:int, name:string, category:?string},
     *     required_level: int,
     *     max_level: int,
     *     active_holders_count: int,
     *     team_size: int,
     *     status: string,
     *     holders: array<int, array{id:int, firstname:?string, lastname:?string, status:string, level:int, on_leave_today:bool}>
     * }>
     */
    public function getProjectKnowledgeCoverage(Project $project): array
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
        $coverage = $this->getProjectKnowledgeCoverage($project);
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

        $sums = [];
        $counts = [];
        foreach ($categories as $category) {
            $sums[$category->id] = 0;
            $counts[$category->id] = 0;
        }

        foreach ($project->users as $user) {
            foreach ($user->skills as $skill) {
                $categoryId = $skill->skill_category_id;
                if (!isset($sums[$categoryId])) {
                    continue;
                }
                $sums[$categoryId] += (int) $skill->pivot->level;
                $counts[$categoryId]++;
            }
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
