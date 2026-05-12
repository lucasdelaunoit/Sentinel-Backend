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

    public function updateSkill(Skill $skill, array $data): Skill
    {
        $skill->update($data);

        return $skill->fresh(['category']);
    }

    public function deleteSkill(Skill $skill): void
    {
        $skill->delete();
    }

    /**
     * <summary>
     *  Soft-delete every Skill that belongs to the given SkillCategory.
     *  Single DB action — caller orchestrates any related deletions.
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
