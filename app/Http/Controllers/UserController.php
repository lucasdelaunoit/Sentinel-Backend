<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachUserSkillRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserSkillRequest;
use App\Http\Resources\CalculationSyncStatusResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\UsersStatsResource;
use App\Http\Resources\UserStatsResource;
use App\Managers\CalculationRunManager;
use App\Managers\UserManager;
use App\Metrics\Snapshots\MetricScope;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManager $userManager,
        private readonly CalculationRunManager $calculationRunManager,
    ) {}

    /**
     * <summary>
     *  Sync status of the user's metric recalculation — state, last calculated
     *  time, live progress. Drives the SyncStatusCard on the employee detail page.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return CalculationSyncStatusResource state, last_calculated_at, progress, error
     */
    public function getUserSyncStatus(User $user): CalculationSyncStatusResource
    {
        // Act (Manager)
        $status = $this->calculationRunManager->getSyncStatus(MetricScope::User, $user->id);

        // Return (Controller)
        return new CalculationSyncStatusResource($status);
    }

    /**
     * <summary>
     *  Manually queue a metrics recalculation for the user (debounced — no-op
     *  when one is already pending). Returns the fresh sync status.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return JsonResponse HTTP 202 with the sync-status payload
     */
    public function triggerUserRecalculation(User $user): JsonResponse
    {
        // Act (Manager)
        $this->calculationRunManager->queueUserRecalculation($user);
        $status = $this->calculationRunManager->getSyncStatus(MetricScope::User, $user->id);

        // Return (Controller)
        return (new CalculationSyncStatusResource($status))->response()->setStatusCode(202);
    }

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
        // Act (Manager)
        $users = $this->userManager->getAgileUsers(QueryParams::fromRequest($request));

        // Return (Controller)
        return UserResource::collection($users);
    }

    /**
     * <summary>
     *  Retrieve the users assigned to a project (paginated, filterable, sortable).
     * </summary>
     *
     * @param Request $request Pagination, filter, sort & search parameters
     * @param Project $project Route-model bound project
     * @return AnonymousResourceCollection Paginated list of project users
     */
    public function getAgileUsersForProject(Request $request, Project $project): AnonymousResourceCollection
    {
        // Act (Manager)
        $users = $this->userManager->getAgileUsersForProject(QueryParams::fromRequest($request), $project);

        // Return (Controller)
        return UserResource::collection($users);
    }

    /**
     * <summary>
     *  Create a new user.
     * </summary>
     *
     * @param StoreUserRequest $request name, email, title, department_id
     * @return JsonResponse Created user — HTTP 201
     */
    public function createUser(StoreUserRequest $request): JsonResponse
    {
        // Act (Manager)
        $user = $this->userManager->createUser($request->validated());

        // Return (Controller)
        return UserResource::make($user)->response()->setStatusCode(201);
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
        // Act (Manager)
        $user = $this->userManager->getUser($user);

        // Return (Controller)
        return new UserResource($user);
    }

    /**
     * <summary>
     *  Update an existing user.
     * </summary>
     *
     * @param UpdateUserRequest $request Fields to update (all optional)
     * @param User $user Route-model bound user
     * @return UserResource Updated user
     */
    public function updateUser(UpdateUserRequest $request, User $user): UserResource
    {
        // Act (Manager)
        $user = $this->userManager->updateUser($user, $request->validated());

        // Return (Controller)
        return UserResource::make($user);
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
        // Act (Manager)
        $this->userManager->deleteUser($user);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Attach a skill to a user.
     * </summary>
     *
     * @param AttachUserSkillRequest $request skill_id and level (1–5)
     * @param User $user Route-model bound user
     * @return JsonResponse HTTP 200 confirmation message
     */
    public function attachSkillToUser(AttachUserSkillRequest $request, User $user): JsonResponse
    {
        // Act (Manager)
        $data = $request->validated();
        $this->userManager->attachSkillToUser($user, $data['skill_id'], $data['level']);

        // Return (Controller)
        return response()->json(['message' => 'Skill added']);
    }

    /**
     * <summary>
     *  Update the proficiency level of an existing user skill.
     * </summary>
     *
     * @param UpdateUserSkillRequest $request level (1–5)
     * @param User $user Route-model bound user
     * @param Skill $skill Route-model bound skill
     * @return JsonResponse HTTP 200 confirmation message
     */
    public function updateUserSkill(UpdateUserSkillRequest $request, User $user, Skill $skill): JsonResponse
    {
        // Act (Manager)
        $this->userManager->updateUserSkill($user, $skill->id, $request->validated()['level']);

        // Return (Controller)
        return response()->json(['message' => 'Skill level updated']);
    }

    /**
     * <summary>
     *  Detach a skill from a user.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @param Skill $skill Route-model bound skill
     * @return JsonResponse HTTP 204 No Content
     */
    public function detachSkillFromUser(User $user, Skill $skill): JsonResponse
    {
        // Act (Manager)
        $this->userManager->detachSkillFromUser($user, $skill->id);

        // Return (Controller)
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
        // Act (Manager)
        $criticality = $this->userManager->getUserCriticality($user);

        // Return (Controller)
        return response()->json($criticality);
    }

    /**
     * <summary>
     *  Recommendation list for a user, derived from the criticality breakdown.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return JsonResponse Recommendation rows {id, icon, title, description, severity, priority}
     */
    public function getUserRecommendations(User $user): JsonResponse
    {
        // Act (Manager)
        $recommendations = $this->userManager->getUserRecommendations($user);

        // Return (Controller)
        return response()->json($recommendations);
    }

    /**
     * <summary>
     *  Retrieve the competency-radar series (per SkillCategory) for a user.
     * </summary>
     *
     * @param User $user Route-model bound user
     * @return JsonResponse Radar rows wrapped in { data: [...] }
     */
    public function getUserCompetencyRadar(User $user): JsonResponse
    {
        // Act (Manager)
        $rows = $this->userManager->getUserCompetencyRadar($user);

        // Return (Controller)
        return response()->json(['data' => $rows]);
    }

    /**
     * <summary>
     *  Get the org's present-capacity percentage for today (share of users with no active absence).
     * </summary>
     *
     * @return JsonResponse { capacity_pct: int } — 0–100
     */
    public function getUsersCapacity(): JsonResponse
    {
        // Act (Manager)
        $capacity = $this->userManager->getUsersCapacity();

        // Return (Controller)
        return response()->json($capacity);
    }

    /**
     * <summary>
     *  Org-wide user stats for the Users page header.
     * </summary>
     *
     * @return JsonResponse total, available, away, critical_users, unique_skill_holders, departments
     */
    public function getUsersStats(): UsersStatsResource
    {
        // Act (Manager)
        $stats = $this->userManager->getUsersStats();

        // Return (Controller)
        return new UsersStatsResource($stats);
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
