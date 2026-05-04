<?php

namespace App\Managers;

use App\Models\Skill;
use App\Models\SkillCategory;
use App\Services\RiskCalculationService;
use Illuminate\Database\Eloquent\Collection;

class SkillManager
{
    public function __construct(
        private readonly RiskCalculationService $risk,
    ) {}

    // Skills

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

    // Categories

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

    public function getKCI(SkillCategory $category): array
    {
        return [
            'category_id'   => $category->id,
            'category_name' => $category->name,
            'kci'           => $this->risk->computeKCI($category),
        ];
    }
}
