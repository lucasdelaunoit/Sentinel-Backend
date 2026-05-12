<?php

namespace App\Managers;

use App\Models\SkillCategory;
use App\Services\RiskCalculationService;
use App\Services\SkillCategoryService;
use App\Services\SkillService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SkillCategoryManager
{
    public function __construct(
        private readonly RiskCalculationService  $riskService,
        private readonly SkillCategoryService    $skillCategoryService,
        private readonly SkillService            $skillService,
    ) {}

    public function getAgileSkillCategories(): Collection
    {
        return $this->skillCategoryService->getAgileSkillCategories();
    }

    public function createCategory(array $data): SkillCategory
    {
        if (SkillCategory::count() >= 8)
            throw new Exception('Maximum of 8 skill categories allowed.');

        return DB::transaction(fn() => $this->skillCategoryService->createCategory($data));
    }

    /**
     * <summary>
     *  Update a SkillCategory. Only the name is mutable. Delegates to SkillCategoryService.
     * </summary>
     *
     * @param SkillCategory $category Target category to update
     * @param array{name: string} $data Validated payload — only the name is applied
     * @return SkillCategory Updated category
     */
    public function updateSkillCategory(SkillCategory $category, array $data): SkillCategory
    {
        return $this->skillCategoryService->updateSkillCategory($category, $data);
    }

    /**
     * <summary>
     *  Soft-delete a SkillCategory and cascade soft-delete to all its skills inside a transaction.
     * </summary>
     *
     * @param SkillCategory $category Target category to soft-delete
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function deleteSkillCategory(SkillCategory $category): void
    {
        DB::transaction(function () use ($category) {
            $this->skillService->deleteSkillsBySkillCategory($category);
            $this->skillCategoryService->deleteSkillCategory($category);
        });
    }

    public function getKCI(SkillCategory $category): array
    {
        return [
            'category_id'   => $category->id,
            'category_name' => $category->name,
            'kci'           => $this->riskService->computeKCI($category),
        ];
    }
}
