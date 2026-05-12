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
