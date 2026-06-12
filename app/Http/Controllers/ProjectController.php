<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachSkillToProjectRequest;
use App\Http\Requests\AttachUserToProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\CalculationSyncStatusResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectStatsResource;
use App\Http\Resources\ProjectsStatsResource;
use App\Managers\CalculationRunManager;
use App\Managers\ProjectManager;
use App\Metrics\Snapshots\MetricScope;
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
        private readonly CalculationRunManager $calculationRunManager,
    ) {}

    /**
     * <summary>
     *  Sync status of the project's metric recalculation — state, last calculated
     *  time, live progress. Drives the SyncStatusCard on the project detail page.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return CalculationSyncStatusResource state, last_calculated_at, progress, error
     */
    public function getProjectSyncStatus(Project $project): CalculationSyncStatusResource
    {
        // Act (Manager)
        $status = $this->calculationRunManager->getSyncStatus(MetricScope::Project, $project->id);

        // Return (Controller)
        return new CalculationSyncStatusResource($status);
    }

    /**
     * <summary>
     *  Manually queue a metrics recalculation for the project (debounced — no-op
     *  when one is already pending). Returns the fresh sync status.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse HTTP 202 with the sync-status payload
     */
    public function triggerProjectRecalculation(Project $project): JsonResponse
    {
        // Act (Manager)
        $this->calculationRunManager->queueProjectRecalculation($project);
        $status = $this->calculationRunManager->getSyncStatus(MetricScope::Project, $project->id);

        // Return (Controller)
        return (new CalculationSyncStatusResource($status))->response()->setStatusCode(202);
    }

    /**
     * <summary>
     *  Aggregate project-wide stats: total, avg_fragility_raw + tier, critical_count, stretched_count.
     * </summary>
     *
     * @return ProjectsStatsResource total, avg_fragility_raw, avg_fragility, critical_count, stretched_count
     */
    public function getProjectsStats(): ProjectsStatsResource
    {
        // Act (Manager)
        $stats = $this->projectManager->getProjectsStats();

        // Return (Controller)
        return new ProjectsStatsResource($stats);
    }

    /**
     * <summary>
     *  Per-project stats card: fragility, team_availability, knowledge_coverage.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return ProjectStatsResource fragility, team_availability, knowledge_coverage
     */
    public function getProjectStats(Project $project): ProjectStatsResource
    {
        // Act (Manager)
        $stats = $this->projectManager->getProjectStats($project);

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
     *  Retrieve all projects assigned to a user (paginated, filterable, sortable).
     * </summary>
     *
     * @param Request $request Pagination, filter, sort & search parameters
     * @param User $user Route-model bound user
     * @return AnonymousResourceCollection Paginated list of projects for the user
     */
    public function getAgileProjectsForUser(Request $request, User $user): AnonymousResourceCollection
    {
        // Act (Manager)
        $projects = $this->projectManager->getAgileProjectsForUser(QueryParams::fromRequest($request), $user);

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
     *  Retrieve project-level metrics: bus_factor, fragility_raw + tier, redundancy.
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
     *  Retrieve the paginated, searchable, sortable, filterable knowledge-coverage breakdown for a
     *  project's skill requirements. Each row carries its first 5 holders plus holders_total.
     * </summary>
     *
     * @param Request $request Pagination, filter & sort query parameters
     * @param Project $project Route-model bound project
     * @return JsonResponse Paginated knowledge-coverage rows
     */
    public function getProjectKnowledgeCoverage(Request $request, Project $project): JsonResponse
    {
        // Act (Manager)
        $rows = $this->projectManager->getProjectKnowledgeCoverage(QueryParams::fromRequest($request), $project);

        // Return (Controller)
        return response()->json($rows);
    }

    /**
     * <summary>
     *  Retrieve the project-wide coverage summary (covered/silo/uncovered/total) over all required
     *  skills, independent of list pagination.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse Summary counts wrapped in { data: {...} }
     */
    public function getProjectKnowledgeCoverageSummary(Project $project): JsonResponse
    {
        // Act (Manager)
        $summary = $this->projectManager->getProjectKnowledgeCoverageSummary($project);

        // Return (Controller)
        return response()->json(['data' => $summary]);
    }

    /**
     * <summary>
     *  Retrieve the full (unpaginated) knowledge-coverage matrix with complete holder lists, for
     *  dashboard cards that aggregate across every required skill.
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse Full matrix rows wrapped in { data: [...] }
     */
    public function getProjectKnowledgeMatrix(Project $project): JsonResponse
    {
        // Act (Manager)
        $rows = $this->projectManager->getProjectKnowledgeMatrix($project);

        // Return (Controller)
        return response()->json(['data' => $rows]);
    }

    /**
     * <summary>
     *  Retrieve the paginated holders of a single skill within a project (level + leave status per
     *  member). Backs the "view all holders" modal.
     * </summary>
     *
     * @param Request $request Pagination, filter & sort query parameters
     * @param Project $project Route-model bound project
     * @param Skill $skill Route-model bound skill
     * @return JsonResponse Paginated holder rows
     */
    public function getProjectSkillHolders(Request $request, Project $project, Skill $skill): JsonResponse
    {
        // Act (Manager)
        $holders = $this->projectManager->getProjectSkillHolders(QueryParams::fromRequest($request), $project, $skill);

        // Return (Controller)
        return response()->json($holders);
    }

    /**
     * <summary>
     *  Retrieve the competency-radar series (per SkillCategory) for a project.
     *  Optional ?scope=required restricts axes to the categories of the project's required skills.
     * </summary>
     *
     * @param Request $request Current HTTP request (optional 'scope' query param: all|required)
     * @param Project $project Route-model bound project
     * @return JsonResponse Radar rows wrapped in { data: [...] }
     */
    public function getProjectCompetencyRadar(Request $request, Project $project): JsonResponse
    {
        // Validate (Controller)
        $scope = $request->query('scope', 'all');
        if (!in_array($scope, ['all', 'required'], true)) {
            $scope = 'all';
        }

        // Act (Manager)
        $rows = $this->projectManager->getProjectCompetencyRadar($project, $scope);

        // Return (Controller)
        return response()->json(['data' => $rows]);
    }

    /**
     * <summary>
     *  Retrieve the prioritized fragility-alert feed for a project (decision-support).
     * </summary>
     *
     * @param Project $project Route-model bound project
     * @return JsonResponse Alert rows wrapped in { data: [...] }
     */
    public function getProjectFragilityAlerts(Project $project): JsonResponse
    {
        // Act (Manager)
        $alerts = $this->projectManager->getProjectFragilityAlerts($project);

        // Return (Controller)
        return response()->json(['data' => $alerts]);
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
