<?php

namespace App\Services;

use App\Models\Skill;
use App\Models\SkillCategory;
use App\Models\User;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class SkillService
{
    /**
     * <summary>
     *  Retrieve all skills (paginated, filterable, sortable).
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @return LengthAwarePaginator Paginated list of skills
     */
    public function getAgileSkills(QueryParams $params): LengthAwarePaginator
    {
        return QueryBuilder::for(Skill::class, $params->toRequest())
            ->with('category')
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('name', 'like', "%{$v}%")),
                AllowedFilter::exact('category_id', 'skill_category_id'),
            ])
            ->allowedSorts(['name'])
            ->defaultSort('name')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    public function createSkill(array $data): Skill
    {
        return Skill::create($data);
    }

    /**
     * <summary>
     *  Update a Skill row with the given payload and return the refreshed model with its category.
     * </summary>
     *
     * @param Skill $skill Target skill
     * @param array<string, mixed> $data Validated fields to update (name?, skill_category_id?)
     * @return Skill Refreshed skill with category eager-loaded
     */
    public function updateSkill(Skill $skill, array $data): Skill
    {
        $skill->update($data);

        return $skill->fresh(['category']);
    }

    /**
     * <summary>
     *  Soft-delete a single Skill row. Does not touch related pivots.
     * </summary>
     *
     * @param Skill $skill Target skill to soft-delete
     * @return void
     */
    public function deleteSkill(Skill $skill): void
    {
        $skill->delete();
    }

    /**
     * <summary>
     *  Return the IDs of every Project that requires the given Skill via project_skill_reqs.
     * </summary>
     *
     * @param Skill $skill Target skill
     * @return SupportCollection<int, int> Collection of project IDs
     */
    public function getProjectsForSkill(Skill $skill): SupportCollection
    {
        return $skill->projects()->pluck('projects.id');
    }

    /**
     * <summary>
     *  Detach the given Skill from every User by clearing its rows in the user_skills pivot.
     * </summary>
     *
     * @param Skill $skill Target skill
     * @return void
     */
    public function detachSkillFromAllUsers(Skill $skill): void
    {
        $skill->users()->detach();
    }

    /**
     * <summary>
     *  Detach the given Skill from every Project by clearing its rows in the project_skill_reqs pivot.
     * </summary>
     *
     * @param Skill $skill Target skill
     * @return void
     */
    public function detachSkillFromAllProjects(Skill $skill): void
    {
        $skill->projects()->detach();
    }

    /**
     * <summary>
     *  Soft-delete every Skill that belongs to the given SkillCategory.
     * </summary>
     *
     * @param SkillCategory $category Category whose skills should be soft-deleted
     * @return void
     */
    public function deleteSkillsBySkillCategory(SkillCategory $category): void
    {
        $category->skills()->delete();
    }

    public function getAgileSkillsForUser(QueryParams $params, User $user): LengthAwarePaginator
    {
        return QueryBuilder::for($user->skills()->with('category'), $params->toRequest())
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('skills.name', 'like', "%{$v}%")),
                AllowedFilter::exact('category_id', 'skill_category_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('level', 'user_skills.level'),
            ])
            ->defaultSort('name')
            ->paginate($params->perPage())
            ->appends($params->rawQuery());
    }

    public function getUserSkills(User $user): SupportCollection
    {
        $user->loadMissing('skills.category');

        return $user->skills->map(fn($skill) => [
            'id'       => $skill->id,
            'name'     => $skill->name,
            'category' => $skill->category?->name,
            'level'    => $skill->pivot->level,
        ]);
    }
}
