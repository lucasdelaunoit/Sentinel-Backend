<?php

namespace App\Services;

use App\Metrics\Scales\AbsenceImpactScale;
use App\Metrics\Scales\FragilityScale;
use App\Metrics\Scales\KnowledgeCoverageScale;
use App\Metrics\Scales\TeamAvailabilityScale;
use App\Metrics\Severity;
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
    ) {}

    // ───────────────────────── /projects/stats ─────────────────────────

    /**
     * <summary>
     *  Total non-archived projects count Stat.
     * </summary>
     *
     * @return Stat
     */
    public function getProjectsTotalStat(): Stat
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
    public function getProjectsAvgFragilityStat(): Stat
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
    public function getProjectsFragileCountStat(): Stat
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
     *  Count of stretched projects (fragility_raw 41-60). Severity WARNING when any, OK otherwise.
     * </summary>
     *
     * @return Stat
     */
    public function getProjectsStretchedCountStat(): Stat
    {
        $count = Project::query()->whereNull('archived_at')->whereBetween('fragility_raw', [41, 60])->count();

        return new Stat(
            value: $count === 0 ? 'None' : (string) $count,
            valueRaw: $count,
            severity: $count > 0 ? Severity::WARNING : Severity::OK,
            insight: $count > 0 ? 'Fragility 41-60' : null,
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

    // ───────────────────────── /dashboard/stats ─────────────────────────

    /**
     * <summary>
     *  Worst-fragility Stat for the dashboard — max(fragility_raw) over active projects.
     *  Insight lists fragile + stretched bucket counts.
     * </summary>
     *
     * @return Stat
     */
    public function getWorstFragilityStat(): Stat
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
     *  Knowledge-coverage Stat — org-wide % of required skills currently 'safe'.
     *  TODO(snapshot): swap live coverage walk for a precomputed org metric snapshot once cron lands.
     * </summary>
     *
     * @return Stat
     */
    public function getKnowledgeCoverageStat(): Stat
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
     *  Caller passes absent user ids — keeps this service stateless about "who is away today".
     *  TODO(snapshot): swap live diff for a precomputed org metric snapshot once cron lands.
     * </summary>
     *
     * @param array<int> $absentUserIds User ids absent today
     * @return Stat
     */
    public function getAbsenceImpactStat(array $absentUserIds): Stat
    {
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
