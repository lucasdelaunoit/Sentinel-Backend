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
     *  Persist a new project record.
     * </summary>
     *
     * @param array $data Validated fields
     * @return Project Newly created project
     */
    public function createProject(array $data): Project
    {
        return Project::create($data);
    }

    /**
     * <summary>
     *  Eager-load the relations needed for the detail view of a project.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Project with users.department, skillRequirements.category, simulations loaded
     */
    public function getProject(Project $project): Project
    {
        return $project->loadMissing([
            'users.department',
            'skillRequirements.category',
            'simulations',
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
}
