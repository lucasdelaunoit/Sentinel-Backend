<?php

namespace App\Services;

use App\Models\Project;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProjectService
{
    /**
     * <summary>
     *  Aggregate project-wide stats for the Projects page header.
     *  Scope: non-archived projects only (managers do not care about archived projects in the header).
     *
     *  Bands (per product spec):
     *    - 0–39  → Healthy
     *    - 40–69 → At Risk (Moderate / Warning)
     *    - 70+   → Fragile
     *
     *  TODO(fragility): real Fragility score is not implemented yet. Spec:
     *    Fragility = 0.40 * BusFactorRisk + 0.35 * CoverageRisk + 0.25 * DependencyRisk
     *    Health    = 100 - Fragility
     *  When the FragilityService lands, swap `risk_score` / `health` reads below for the new
     *  precomputed columns (e.g. `fragility`) populated by RecalculateProjectRiskJob.
     *
     *  TODO(compute mode): currently reads precomputed columns (`risk_score`, `health`). Decide later
     *  whether stats should be computed live in the Service vs. aggregated from columns updated by
     *  the recalculation job. Live = always fresh but slower; precomputed = fast but depends on jobs.
     * </summary>
     *
     * @return array total, avg_health, fragile, at_risk
     */
    public function getProjectsStats(): array
    {
        $base = Project::query()->whereNull('archived_at');

        $total = (clone $base)->count();
        $avgHealth = (int) round((clone $base)->avg('health') ?? 0);
        $fragile = (clone $base)->where('risk_score', '>=', 70)->count();
        $atRisk = (clone $base)->whereBetween('risk_score', [40, 69])->count();

        return [
            'total' => $total,
            'avg_health' => $avgHealth,
            'fragile' => $fragile,
            'at_risk' => $atRisk,
        ];
    }

    /**
     * <summary>
     *  Assemble per-project stats card payload from the project's precomputed columns and
     *  current team availability. Returns: risk_score, bus_factor, health_score, team{total, away}.
     *  "away" counts attached users with an absence active today.
     * </summary>
     *
     * @param Project $project Target project
     * @return array risk_score, bus_factor, health_score, team{total, away}
     */
    public function getProjectStats(Project $project): array
    {
        $today = now()->toDateString();

        $total = $project->users()->count();
        $away  = $project->users()
            ->whereHas('absences', fn($q) => $q
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
            )
            ->count();

        return [
            'risk_score'   => (int) $project->risk_score,
            'bus_factor'   => (int) $project->bus_factor,
            'health_score' => (int) $project->health,
            'team'         => [
                'total' => $total,
                'away'  => $away,
            ],
        ];
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
                AllowedSort::field('risk_score'),
                AllowedSort::field('health'),
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
