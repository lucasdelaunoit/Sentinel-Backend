<?php

namespace App\Http\Controllers;

use App\Managers\SkillManager;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    public function __construct(private readonly SkillManager $manager) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'search']);

        return response()->json($this->manager->listSkills($filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'skill_category_id' => ['required', 'integer', 'exists:skill_categories,id'],
        ]);

        return response()->json($this->manager->createSkill($data), 201);
    }

    public function update(Request $request, Skill $skill): JsonResponse
    {
        $data = $request->validate([
            'name'              => ['sometimes', 'string', 'max:255'],
            'skill_category_id' => ['sometimes', 'integer', 'exists:skill_categories,id'],
        ]);

        return response()->json($this->manager->updateSkill($skill, $data));
    }

    public function destroy(Skill $skill): JsonResponse
    {
        $this->manager->deleteSkill($skill);

        return response()->json(null, 204);
    }
}
