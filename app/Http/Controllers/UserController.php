<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachUserSkillRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserSkillRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserStatsResource;
use App\Managers\UserManager;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManager $userManager
    ) {}

    /**
     * <summary>
     *  Retrieve all users (paginated, filterable, sortable).
     * </summary>
     *
     * @param Request $request Pagination, filter, sort & search parameters
     * @return AnonymousResourceCollection Paginated list of users
     */
    public function getAgileUsers(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->userManager->getAgileUsers($request));
    }

    /**
     * <summary>
     *  Create a new user.
     * </summary>
     *
     * @param StoreUserRequest $request name, email, title, department_id
     * @return UserResource Created user — HTTP 201
     */
    public function createUser(StoreUserRequest $request): UserResource
    {
        return UserResource::make($this->userManager->createUser($request->validated()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * <summary>
     *  Retrieve a single user with all relations.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return UserResource User with department, skills, projects and absences
     */
    public function getUser(User $user): UserResource
    {
        return new UserResource($user);
    }

    /**
     * <summary>
     *  Update an existing user.
     * </summary>
     *
     * @param UpdateUserRequest $request Fields to update (all optional)
     * @param User              $user    Route-model bound user
     * @return UserResource Updated user
     */
    public function updateUser(UpdateUserRequest $request, User $user): UserResource
    {
        return UserResource::make($this->userManager->updateUser($user, $request->validated()));
    }

    /**
     * <summary>
     *  Delete a user.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteUser(User $user): JsonResponse
    {
        $this->userManager->deleteUser($user);

        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Attach a skill to a user.
     * </summary>
     *
     * @param AttachUserSkillRequest $request skill_id and level (1–5)
     * @param User                   $user    Route-model bound user
     * @return JsonResponse HTTP 200 confirmation message
     */
    public function attachSkillToUser(AttachUserSkillRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $this->userManager->attachSkillToUser($user, $data['skill_id'], $data['level']);

        return response()->json(['message' => 'Skill added']);
    }

    /**
     * <summary>
     *  Update the proficiency level of an existing user skill.
     * </summary>
     *
     * @param UpdateUserSkillRequest $request level (1–5)
     * @param User                   $user    Route-model bound user
     * @param Skill                  $skill   Route-model bound skill
     * @return JsonResponse HTTP 200 confirmation message
     */
    public function updateUserSkill(UpdateUserSkillRequest $request, User $user, Skill $skill): JsonResponse
    {
        $this->userManager->updateUserSkill($user, $skill->id, $request->validated()['level']);

        return response()->json(['message' => 'Skill level updated']);
    }

    /**
     * <summary>
     *  Detach a skill from a user.
     * </summary>
     *
     * @param User  $user  Route-model bound user
     * @param Skill $skill Route-model bound skill
     * @return JsonResponse HTTP 204 No Content
     */
    public function detachSkillFromUser(User $user, Skill $skill): JsonResponse
    {
        $this->userManager->detachSkillFromUser($user, $skill->id);

        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Compute criticality score for a user.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return JsonResponse Criticality breakdown (silo count, bus factor contributions, score)
     */
    public function getUserCriticality(User $user): JsonResponse
    {
        return response()->json($this->userManager->getUserCriticality($user));
    }

    /**
     * <summary>
     *  Get today's availability status for all users.
     * </summary>
     *
     * @return JsonResponse capacity_pct, total, and top-5 employee preview
     */
    public function getUsersTodayStatus(): JsonResponse
    {
        return response()->json($this->userManager->getUsersTodayStatus());
    }

    /**
     * <summary>
     *  Get stats for a specific user: criticality, bus factor in org, skill distribution, active projects.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return UserStatsResource criticality, bus_factor_in_org, skills, active_projects
     */
    public function getUserStats(User $user): UserStatsResource
    {
        // Act (Manager)
        $userStats = $this->userManager->getUserStats($user);

        // Return (Controller)
        return new UserStatsResource($userStats);
    }
}
