<?php

namespace App\Services;

use App\Models\Skill;
use App\Models\SkillCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
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
     * @param Request $request Pagination, filter, sort & search parameters
     * @return LengthAwarePaginator Paginated list of skill
     */
    public function getAgileSkills(Request $request): LengthAwarePaginator
    {
        if ($request->filled('search') && !$request->has('filter.search')) {
            $request->merge(['filter' => array_merge($request->input('filter', []), ['search' => $request->input('search')])]);
        }

        return QueryBuilder::for(Skill::class, $request)
            ->with('category')
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    $query->where('name', 'like', "%{$value}%");
                }),
            ])
            ->defaultSort('name')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());
    }


    public function listSkills(array $filters = []): Collection
    {
        return Skill::query()
            ->with('category')
            ->when(isset($filters['category_id']), fn($q) => $q->where('skill_category_id', $filters['category_id']))
            ->when(isset($filters['search']), fn($q) => $q->where('name', 'like', "%{$filters['search']}%"))
            ->orderBy('name')
            ->get();
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

    public function listCategories(): Collection
    {
        return SkillCategory::withCount('skills')->orderBy('name')->get();
    }

    public function createCategory(array $data): SkillCategory
    {
        return SkillCategory::create($data);
    }

    public function updateCategory(SkillCategory $category, array $data): SkillCategory
    {
        $category->update($data);

        return $category->fresh();
    }

    public function deleteCategory(SkillCategory $category): void
    {
        $category->delete();
    }

    public function getAgileSkillsForUser(Request $request, User $user): LengthAwarePaginator
    {
        return QueryBuilder::for($user->skills()->with('category'), $request)
            ->allowedFilters([
                AllowedFilter::callback('search', fn($q, $v) => $q->where('skills.name', 'like', "%{$v}%")),
                AllowedFilter::exact('category_id', 'skill_category_id'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('level', 'user_skills.level'),
            ])
            ->defaultSort('name')
            ->paginate($request->integer('per_page', 20))
            ->appends($request->query());
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
