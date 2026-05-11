<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSkillCategoryRequest;
use App\Http\Resources\SkillCategoryResource;
use App\Managers\SkillCategoryManager;
use App\Managers\SkillManager;
use App\Models\SkillCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillCategoryController extends Controller
{
    public function __construct(
        private readonly SkillCategoryManager $skillCategoryManager
    ) {}

    /**
     * <summary>
     *  Retrieve all skill categories.
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
     *  Create a new skill category.
     * </summary>
     *
     * @param StoreSkillCategoryRequest $request name (unique)
     * @return JsonResponse Created category — HTTP 201
     */
    public function createCategory(StoreSkillCategoryRequest $request): JsonResponse
    {
        // Act (Manager)
        $category = $this->skillManager->createCategory($request->validated());

        // Return (Controller)
        return response()->json($category, 201);
    }

    /**
     * <summary>
     *  Delete a skill category. Rejected with 409 if any skills reference it.
     * </summary>
     *
     * @param SkillCategory $skillCategory Route-model bound category
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteCategory(SkillCategory $skillCategory): JsonResponse
    {
        // Act (Manager)
        $this->skillManager->deleteCategory($skillCategory);

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
        $kci = $this->skillManager->getKCI($skillCategory);

        // Return (Controller)
        return response()->json($kci);
    }
}
