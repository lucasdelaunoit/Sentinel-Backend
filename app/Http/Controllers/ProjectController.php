<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachSkillToProjectRequest;
use App\Http\Requests\AttachUserToProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectStatsResource;
use App\Managers\ProjectManager;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Support\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectManager $projectManager,
    ) {}

    /**
     * <summary>
     *  Aggregate project-wide stats: total, avg_health, fragile count, at_risk count.
     * </summary>
     *
     * @return ProjectStatsResource total, avg_health, fragile, at_risk
     */
    public function getProjectStats(): ProjectStatsResource
    {
        // Act (Manager)
        $stats = $this->projectManager->getProjectStats();

        // Return (Controller)
        return new ProjectStatsResource($stats);
    }

    /**
     * <summary>
     *  Retrieve all projects (paginated, filterable, sortable).
     * </summary>
     *
     * @param Request $request Pagination, filter, sort & search parameters
     * @return AnonymousResourceCollection Paginated list of projects
     */
    public function getAgileProjects(Request $request): AnonymousResourceCollection
    {
        // Act (Manager)
        $projects = $this->projectManager->getAgileProjects(QueryParams::fromRequest($request));

        // Return (Controller)
        return ProjectResource::collection($projects);
    }

    /**
     * <summary>
     *  Create a new project.
     * </summary>
     *
     * @param StoreProjectRequest $request Validated payload
     * @return JsonResponse Created project — HTTP 201
     */
    public function createProject(StoreProjectRequest $request): JsonResponse
    {
        // Act (Manager)
        $project = $this->projectManager->createProject($request->validated());

        // Return (Controller)
        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    /**
     * <summary>
     *  Retrieve a single project with detail-view relations.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Project with users and skill requirements
     */
    public function getProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->getProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Update a project.
     * </summary>
     *
     * @param UpdateProjectRequest $request Validated payload
     * @param Project $project Route-model bound project
     * @return ProjectResource Updated project
     */
    public function updateProject(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->updateProject($project, $request->validated());

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Delete a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse HTTP 204 No Content
     */
    public function deleteProject(Project $project): JsonResponse
    {
        // Act (Manager)
        $this->projectManager->deleteProject($project);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Retrieve the skill-coverage matrix for a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse Coverage matrix
     */
    public function getProjectCoverage(Project $project): JsonResponse
    {
        // Act (Manager)
        $coverage = $this->projectManager->getProjectCoverage($project);

        // Return (Controller)
        return response()->json($coverage);
    }

    /**
     * <summary>
     *  Retrieve project-level metrics: bus_factor, risk_score, health, redundancy.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse Metrics payload
     */
    public function getProjectMetrics(Project $project): JsonResponse
    {
        // Act (Manager)
        $metrics = $this->projectManager->getProjectMetrics($project);

        // Return (Controller)
        return response()->json($metrics);
    }

    /**
     * <summary>
     *  Attach a user to a project.
     * </summary>
     *
     * @param AttachUserToProjectRequest $request Validated payload (user_id)
     * @param Project $project Route-model bound project
     * @return JsonResponse HTTP 204 No Content
     */
    public function attachUserToProject(AttachUserToProjectRequest $request, Project $project): JsonResponse
    {
        // Act (Manager)
        $this->projectManager->attachUserToProject($project, (int) $request->validated('user_id'));

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Detach a user from a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @param User $user Route-model bound user
     * @return JsonResponse HTTP 204 No Content
     */
    public function detachUserFromProject(Project $project, User $user): JsonResponse
    {
        // Act (Manager)
        $this->projectManager->detachUserFromProject($project, $user->id);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Attach a skill requirement to a project.
     * </summary>
     *
     * @param AttachSkillToProjectRequest $request Validated payload (skill_id, required_level)
     * @param Project $project Route-model bound project
     * @return JsonResponse HTTP 204 No Content
     */
    public function attachSkillToProject(AttachSkillToProjectRequest $request, Project $project): JsonResponse
    {
        // Act (Manager)
        $this->projectManager->attachSkillToProject(
            $project,
            (int) $request->validated('skill_id'),
            (int) $request->validated('required_level'),
        );

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Detach a skill requirement from a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @param Skill $skill Route-model bound skill
     * @return JsonResponse HTTP 204 No Content
     */
    public function detachSkillFromProject(Project $project, Skill $skill): JsonResponse
    {
        // Act (Manager)
        $this->projectManager->detachSkillFromProject($project, $skill->id);

        // Return (Controller)
        return response()->json(null, 204);
    }

    /**
     * <summary>
     *  Pause a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Refreshed project
     */
    public function pauseProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->pauseProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Resume a paused project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Refreshed project
     */
    public function resumeProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->resumeProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Mark a project completed.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Refreshed project
     */
    public function completeProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->completeProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Reopen a completed project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Refreshed project
     */
    public function reopenProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->reopenProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Archive a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Refreshed project
     */
    public function archiveProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->archiveProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }

    /**
     * <summary>
     *  Unarchive a project.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectResource Refreshed project
     */
    public function unarchiveProject(Project $project): ProjectResource
    {
        // Act (Manager)
        $project = $this->projectManager->unarchiveProject($project);

        // Return (Controller)
        return ProjectResource::make($project);
    }
}
