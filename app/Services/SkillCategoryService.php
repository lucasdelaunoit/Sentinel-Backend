<?php

namespace App\Services;

use App\Models\SkillCategory;
use Illuminate\Database\Eloquent\Collection;

class SkillCategoryService
{
    public function getAgileSkillCategories(): Collection
    {
        return SkillCategory::withCount('skills')->orderBy('name')->get();
    }

    public function createCategory(array $data): SkillCategory
    {
        return SkillCategory::create($data);
    }

    /**
     * <summary>
     *  Update a single SkillCategory row. Only the name field is mutable.
     * </summary>
     *
     * @param SkillCategory $category Target category to update
     * @param array{name: string} $data Validated payload — only the name is applied
     * @return SkillCategory Freshly reloaded category
     */
    public function updateSkillCategory(SkillCategory $category, array $data): SkillCategory
    {
        $category->update(['name' => $data['name']]);

        return $category->fresh();
    }

    /**
     * <summary>
     *  Soft-delete a single SkillCategory row.
     * </summary>
     *
     * @param SkillCategory $category Target category to soft-delete
     * @return void
     */
    public function deleteSkillCategory(SkillCategory $category): void
    {
        $category->delete();
    }
}
