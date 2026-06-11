<?php

namespace App\Services;

use App\Models\SkillCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class SkillCategoryService
{
    public function __construct(
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    /**
     * <summary>
     *  Retrieve all skill categories with their skill count, ordered by name.
     * </summary>
     *
     * @return Collection<int, SkillCategory> Categories with skills_count loaded
     */
    public function getAgileSkillCategories(): Collection
    {
        return SkillCategory::withCount('skills')->orderBy('name')->get();
    }

    /**
     * <summary>
     *  Count all SkillCategory rows. Used by the Manager to enforce the category limit.
     * </summary>
     *
     * @return int Number of existing skill categories
     */
    public function countSkillCategories(): int
    {
        return SkillCategory::count();
    }

    /**
     * <summary>
     *  Knowledge Coverage Index for a skill category. Percentage of category-skill-holders
     *  whose level meets the kci_min_level threshold from OrganizationSetting.
     *  Returns 100.0 when the category has no skills (vacuously safe), 0.0 when no holders.
     * </summary>
     *
     * @param SkillCategory $category Target category
     * @return float 0-100
     */
    public function getSkillCategoryKCI(SkillCategory $category): float
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $minLevel = (int) $settings->kci_min_level;

        $skillIds = $category->skills()->pluck('skills.id')->all();
        if ($skillIds === []) return 100.0;

        $totalHolders = User::whereHas('skills', fn($q) => $q->whereIn('skills.id', $skillIds))->count();
        if ($totalHolders === 0) return 0.0;

        $proficient = User::whereHas('skills', fn($q) =>
            $q->whereIn('skills.id', $skillIds)->where('user_skills.level', '>=', $minLevel)
        )->count();

        return round(($proficient / $totalHolders) * 100, 1);
    }

    /**
     * <summary>
     *  Create a single SkillCategory row. Limit enforcement is the Manager's job.
     * </summary>
     *
     * @param array{name: string} $data Validated payload
     * @return SkillCategory Newly created category
     */
    public function createSkillCategory(array $data): SkillCategory
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
