<?php

namespace App\Http\Controllers;

use App\Managers\SkillManager;
use App\Models\SkillCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillCategoryController extends Controller
{
    public function __construct(private readonly SkillManager $manager) {}

    public function index(): JsonResponse
    {
        return response()->json($this->manager->listCategories());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:skill_categories,name'],
        ]);

        return response()->json($this->manager->createCategory($data), 201);
    }

    public function update(Request $request, SkillCategory $skillCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', "unique:skill_categories,name,{$skillCategory->id}"],
        ]);

        return response()->json($this->manager->updateCategory($skillCategory, $data));
    }

    public function destroy(SkillCategory $skillCategory): JsonResponse
    {
        $this->manager->deleteCategory($skillCategory);

        return response()->json(null, 204);
    }

    public function kci(SkillCategory $skillCategory): JsonResponse
    {
        return response()->json($this->manager->getKCI($skillCategory));
    }
}
