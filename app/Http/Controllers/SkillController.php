<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSkillRequest;
use App\Http\Requests\UpdateSkillRequest;
use App\Managers\SkillManager;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    public function __construct(
        private readonly SkillManager $skillManager
    ) {}

    /**
     * <summary>
     *  Retrieve paginated, filterable, sortable list of skills for a specific user.
     * </summary>
     *
     * @param Request $request Pagination, filter (search, category_id), sort parameters
     * @param User    $user    Route-model bound user
     * @return LengthAwarePaginator Paginated skills with category
     */
    public function getAgileSkillsForUser(Request $request, User $user): LengthAwarePaginator
    {
        // Act (Manager)
        return $this->skillManager->getAgileSkillsForUser($request, $user);
    }

    /**
     * <summary>
     *  Retrieve all skills (filterable by category and search term).
     * </summary>
     *
     * @param Request $request Filter parameters: category_id, search
     * @return JsonResponse Collection of skills with category
     */
    public function listSkills(Request $request): JsonResponse
    {
        // Act (Manager)
        $skills = $this->skillManager->listSkills($request->only(['category_id', 'search']));

        // Return (Controller)
        return response()->json($skills);
    }

    /**
     * <summary>
     *  Create a new skill.
     * </summary>
     *
     * @param StoreSkillRequest $request name, skill_category_id
     * @return JsonResponse Created skill — HTTP 201
     */
    public function createSkill(StoreSkillRequest $request): JsonResponse
    {
        // Act (Manager)
        $skill = $this->skillManager->createSkill($request->validated());

        // Return (Controller)
        return response()->json($skill, 201);
    }

    /**
     * <summary>
     *  Update an existing skill.
     * </summary>
     *
     * @param UpdateSkillRequest $request Fields to update (all optional)
     * @param Skill              $skill   Route-model bound skill
     * @return JsonResponse Updated skill
     */
    public function updateSkill(UpdateSkillRequest $request, Skill $skill): JsonResponse
    {
        // Act (Manager)
        $skill = $this->skillManager->updateSkill($skill, $request->validated());

        // Return (Controller)
        return response()->json($skill);
    }

    /**
     * <summary>
     *  Delete a skill.
     * </summary>
     *
     * @param Skill $skill Route-model bound skill
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteSkill(Skill $skill): JsonResponse
    {
        // Act (Manager)
        $this->skillManager->deleteSkill($skill);

        // Return (Controller)
        return response()->json(null, 204);
    }
}
