<?php

namespace App\Managers;

use App\Exceptions\SkillCategoryLimitExceededException;
use App\Models\SkillCategory;
use App\Services\SkillCategoryService;
use App\Services\SkillService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SkillCategoryManager
{
    private const MAX_CATEGORIES = 8;

    public function __construct(
        private readonly SkillCategoryService $skillCategoryService,
        private readonly SkillService $skillService,
    ) {}

    /**
     * <summary>
     *  Retrieve all skill categories with their skill count.
     * </summary>
     *
     * @return Collection<int, SkillCategory> Categories with skills_count loaded
     */
    public function getAgileSkillCategories(): Collection
    {
        return $this->skillCategoryService->getAgileSkillCategories();
    }

    /**
     * <summary>
     *  Create a SkillCategory inside a transaction. Guards the org-wide category limit first.
     * </summary>
     *
     * @param array{name: string} $data Validated payload
     * @return SkillCategory Newly created category
     * @throws SkillCategoryLimitExceededException When the category limit is already reached
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function createSkillCategory(array $data): SkillCategory
    {
        if ($this->skillCategoryService->countSkillCategories() >= self::MAX_CATEGORIES) {
            throw new SkillCategoryLimitExceededException(self::MAX_CATEGORIES);
        }

        return DB::transaction(fn() => $this->skillCategoryService->createSkillCategory($data));
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

    /**
     * <summary>
     *  Assemble the KCI (Knowledge Coverage Index) payload for a category.
     * </summary>
     *
     * @param SkillCategory $category Target category
     * @return array{category_id: int, category_name: string, kci: float} KCI payload
     */
    public function getSkillCategoryKCI(SkillCategory $category): array
    {
        return [
            'category_id' => $category->id,
            'category_name' => $category->name,
            'kci' => $this->skillCategoryService->getSkillCategoryKCI($category),
        ];
    }
}
