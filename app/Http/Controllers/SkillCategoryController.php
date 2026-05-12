<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSkillCategoryRequest;
use App\Http\Resources\SkillCategoryResource;
use App\Managers\SkillCategoryManager;
use App\Models\SkillCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SkillCategoryController extends Controller
{
    public function __construct(
        private readonly SkillCategoryManager $skillCategoryManager
    ) {}

    /**
     * <summary>
     *  Retrieve all skill categories with skill count.
     * </summary>
     *
     * @return JsonResponse Collection of skill categories
     */
    public function getAgileSkillCategories(): JsonResponse
    {
        // Act (Manager)
        $skillCategories = $this->skillCategoryManager->getAgileSkillCategories();

        // Return (Controller)
        return response()->json(SkillCategoryResource::collection($skillCategories)->resolve());
    }

    /**
     * <summary>
     *  Create a new skill category. Rejected with 422 if 8 categories already exist.
     * </summary>
     *
     * @param StoreSkillCategoryRequest $request name (unique)
     * @return SkillCategoryResource Created category — HTTP 201
     */
    public function createCategory(StoreSkillCategoryRequest $request): SkillCategoryResource
    {
        // Act (Manager)
        $category = $this->skillCategoryManager->createCategory($request->validated());

        // Return (Controller)
        return SkillCategoryResource::make($category)->response()->setStatusCode(201);
    }

    /**
     * <summary>
     *  Soft-delete a SkillCategory. All linked skills are cascade soft-deleted.
     * </summary>
     *
     * @param SkillCategory $skillCategory Route-model bound category
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteSkillCategory(SkillCategory $skillCategory): JsonResponse
    {
        // Act (Manager)
        $this->skillCategoryManager->deleteSkillCategory($skillCategory);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Compute KCI (Knowledge Coverage Index) for a category.
     * </summary>
     *
     * @param SkillCategory $skillCategory Route-model bound category
     * @return JsonResponse category_id, category_name, kci
     */
    public function getKCI(SkillCategory $skillCategory): JsonResponse
    {
        // Act (Manager)
        $kci = $this->skillCategoryManager->getKCI($skillCategory);

        // Return (Controller)
        return response()->json($kci);
    }
}
