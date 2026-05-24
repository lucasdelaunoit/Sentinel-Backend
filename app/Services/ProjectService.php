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
use App\Metrics\Stat;
use App\Models\Project;
use App\Support\QueryParams;
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

    /**
     * <summary>
     *  Total non-archived projects count Stat — fresh SQL compute. Used by snapshot writer.
     * </summary>
     *
     * @return Stat
     */
    public function computeProjectsTotalStat(): Stat
    {
        $total = Project::query()->whereNull('archived_at')->count();

        return new Stat(
            value: "{$total} " . ($total === 1 ? 'project' : 'projects'),
            valueRaw: $total,
            severity: Severity::OK,
            insight: 'Active projects',
        );
    }

    /**
     * <summary>
     *  Average fragility Stat across non-archived projects — reads precomputed fragility_raw column.
     * </summary>
     *
     * @return Stat
     */
    public function computeProjectsAvgFragilityStat(): Stat
    {
        $avg = (int) round(Project::query()->whereNull('archived_at')->avg('fragility_raw') ?? 0);

        return Stat::fromScale(FragilityScale::fromRaw($avg), $avg, "Score: {$avg}/100");
    }

    /**
     * <summary>
     *  Count of fragile projects (fragility_raw &gt; 60). Severity CRITICAL when any, OK otherwise.
     * </summary>
     *
     * @return Stat
     */
    public function computeProjectsFragileCountStat(): Stat
    {
        $count = Project::query()->whereNull('archived_at')->where('fragility_raw', '>', 60)->count();

        return new Stat(
            value: $count === 0 ? 'Healthy' : (string) $count,
            valueRaw: $count,
            severity: $count > 0 ? Severity::CRITICAL : Severity::OK,
            insight: $count > 0 ? 'Fragility > 60' : null,
        );
    }

    /**
     * <summary>
     *  Deadline-pressure Stat — count of non-archived, non-completed projects with deadline in next 14 days.
     *  Tier label by count: 0 None · 1-2 Low · 3-4 Moderate · 5+ High.
     *  Severity: 0 ok · 1-4 warning · 5+ critical.
     * </summary>
     *
     * @return Stat
     */
    public function computeProjectsDeadlinePressureStat(): Stat
    {
        $today = now()->startOfDay();
        $horizon = $today->copy()->addDays(14);

        $count = Project::query()
            ->whereNull('archived_at')
            ->whereNull('completed_at')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [$today->toDateString(), $horizon->toDateString()])
            ->count();

        [$label, $severity] = match (true) {
            $count >= 5 => ['High', Severity::CRITICAL],
            $count >= 3 => ['Moderate', Severity::WARNING],
            $count >= 1 => ['Low', Severity::WARNING],
            default => ['None', Severity::OK],
        };

        return new Stat(
            value: $label,
            valueRaw: $count,
            severity: $severity,
            insight: $count > 0
                ? "{$count} deadline" . ($count > 1 ? 's' : '') . ' in the next 14 days'
                : 'No deadlines in the next 14 days',
        );
    }

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
     *  Worst-fragility Stat for the dashboard — max(fragility_raw) over active projects.
     *  Insight lists fragile + stretched bucket counts.
     * </summary>
     *
     * @return Stat
     */
    public function computeWorstFragilityStat(): Stat
    {
        $scores = Project::active()->pluck('fragility_raw');

        $worst = (int) ($scores->max() ?? 0);
        $fragile = $scores->filter(fn($v) => $v > 60)->count();
        $stretched = $scores->filter(fn($v) => $v > 40 && $v <= 60)->count();

        $parts = [];
        if ($fragile > 0) {
            $parts[] = "{$fragile} fragile";
        }
        if ($stretched > 0) {
            $parts[] = "{$stretched} stretched";
        }
        $insight = empty($parts) ? 'All projects healthy' : implode(' · ', $parts);

        return Stat::fromScale(FragilityScale::fromRaw($worst), $worst, $insight);
    }

    /**
     * <summary>
     *  Knowledge-coverage Stat — org-wide % of required skills currently 'safe'. Fresh compute, used by snapshot writer.
     * </summary>
     *
     * @return Stat
     */
    public function computeKnowledgeCoverageStat(): Stat
    {
        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $total = 0;
        $safe = 0;

        foreach ($projects as $project) {
            foreach ($this->coverageService->getCoverage($project) as $skill) {
                $total++;
                if ($skill['status'] === 'safe') {
                    $safe++;
                }
            }
        }

        $underCovered = $total - $safe;
        $pct = $total > 0 ? (int) round(($safe / $total) * 100) : 100;

        $insight = $underCovered > 0
            ? "{$underCovered} skill" . ($underCovered > 1 ? 's' : '') . ' under-covered'
            : 'All skills covered';

        return Stat::display("{$pct}%", $pct, KnowledgeCoverageScale::fromRaw($pct), $insight);
    }

    /**
     * <summary>
     *  Absence-impact Stat — count of skills that flipped to 'uncovered' because of today's absences.
     *  Resolves today's absent user ids inline (no caller param). Fresh compute, used by snapshot writer.
     * </summary>
     *
     * @return Stat
     */
    public function computeAbsenceImpactStat(): Stat
    {
        $today = now()->toDateString();
        $absentUserIds = \App\Models\User::whereHas('absences', fn($q) => $q
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();

        if (empty($absentUserIds)) {
            return Stat::fromScale(AbsenceImpactScale::fromRaw(0), 0, 'No impact from absences');
        }

        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $count = 0;
        foreach ($projects as $project) {
            $baseline = $this->coverageService->getCoverage($project);
            $withAbsence = $this->coverageService->getCoverageAfterAbsence($project, $absentUserIds);

            foreach ($withAbsence as $skillId => $simSkill) {
                if (
                    $simSkill['status'] === 'uncovered' &&
                    ($baseline[$skillId]['status'] ?? 'uncovered') !== 'uncovered'
                ) {
                    $count++;
                }
            }
        }

        $insight = $count > 0
            ? "{$count} skill" . ($count > 1 ? 's' : '') . ' became uncovered'
            : 'No impact from absences';

        return Stat::fromScale(AbsenceImpactScale::fromRaw($count), $count, $insight);
    }

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
                AllowedFilter::exact('status'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('status'),
                AllowedSort::field('progress'),
                AllowedSort::field('fragility_raw'),
                AllowedSort::field('created_at'),
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
}
