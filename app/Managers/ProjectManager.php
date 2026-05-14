<?php

namespace App\Managers;

use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProjectManager
{
    public function __construct(
        private readonly SkillCoverageService   $coverageService,
        private readonly RiskCalculationService $riskService,
        private readonly ProjectService         $projectService,
    ) {}

    /**
     * <summary>
     *  Aggregate project-wide stats for the Projects page header: total, avg_health, fragile, at_risk.
     * </summary>
     *
     * @return array total, avg_health, fragile, at_risk
     */
    public function getProjectStats(): array
    {
        return $this->projectService->getProjectStats();
    }

    /**
     * <summary>
     *  Retrieve all projects (paginated, filterable, sortable).
     * </summary>
     *
     * @param QueryParams $params Normalized pagination, filter & sort parameters
     * @return LengthAwarePaginator Paginated list of projects
     */
    public function getAgileProjects(QueryParams $params): LengthAwarePaginator
    {
        return $this->projectService->getAgileProjects($params);
    }

    /**
     * <summary>
     *  Create a new project inside a transaction, then dispatch its initial risk recalculation.
     * </summary>
     *
     * @param array $data Validated fields
     * @return Project Newly created project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function createProject(array $data): Project
    {
        $project = DB::transaction(fn() => $this->projectService->createProject($data));

        RecalculateProjectRiskJob::dispatch($project);

        return $project;
    }

    /**
     * <summary>
     *  Retrieve a project with its detail-view relations eager-loaded.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Project with users.department, skillRequirements.category, simulations loaded
     */
    public function getProject(Project $project): Project
    {
        return $this->projectService->getProject($project);
    }

    /**
     * <summary>
     *  Update a project inside a transaction. Dispatches RecalculateProjectRiskJob when status or progress changed.
     * </summary>
     *
     * @param Project $project Target project
     * @param array $data Validated fields to update
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function updateProject(Project $project, array $data): Project
    {
        return DB::transaction(fn() => $this->projectService->updateProject($project, $data));
    }

    /**
     * <summary>
     *  Delete a project inside a transaction.
     * </summary>
     *
     * @param Project $project Target project
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function deleteProject(Project $project): void
    {
        DB::transaction(fn() => $this->projectService->deleteProject($project));
    }

    /**
     * <summary>
     *  Compute the skill-coverage matrix for a project.
     * </summary>
     *
     * @param Project $project Target project
     * @return array Coverage matrix keyed by skill id
     */
    public function getProjectCoverage(Project $project): array
    {
        return $this->coverageService->getCoverage($project);
    }

    /**
     * <summary>
     *  Assemble project-level metrics: bus_factor, risk_score, health, redundancy.
     * </summary>
     *
     * @param Project $project Target project
     * @return array bus_factor, risk_score, health, redundancy
     */
    public function getProjectMetrics(Project $project): array
    {
        return [
            'bus_factor' => $this->riskService->computeBusFactor($project),
            'risk_score' => $this->riskService->computeRiskScore($project),
            'health'     => $this->riskService->computeHealthScore($project),
            'redundancy' => $this->coverageService->getRedundancy($project),
        ];
    }

    /**
     * <summary>
     *  Attach a user to a project inside a transaction, then dispatch project risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @param int $userId User id to attach
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function attachUserToProject(Project $project, int $userId): void
    {
        DB::transaction(fn() => $this->projectService->attachUserToProject($project, $userId));

        RecalculateProjectRiskJob::dispatch($project);
    }

    /**
     * <summary>
     *  Detach a user from a project inside a transaction, then dispatch project risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @param int $userId User id to detach
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function detachUserFromProject(Project $project, int $userId): void
    {
        DB::transaction(fn() => $this->projectService->detachUserFromProject($project, $userId));

        RecalculateProjectRiskJob::dispatch($project);
    }

    /**
     * <summary>
     *  Attach a skill requirement to a project inside a transaction, then dispatch project risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @param int $skillId Skill id to require
     * @param int $requiredLevel Required level (1–5)
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function attachSkillToProject(Project $project, int $skillId, int $requiredLevel): void
    {
        DB::transaction(fn() => $this->projectService->attachSkillToProject($project, $skillId, $requiredLevel));

        RecalculateProjectRiskJob::dispatch($project);
    }

    /**
     * <summary>
     *  Detach a skill requirement from a project inside a transaction, then dispatch project risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @param int $skillId Skill id to detach
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function detachSkillFromProject(Project $project, int $skillId): void
    {
        DB::transaction(fn() => $this->projectService->detachSkillFromProject($project, $skillId));

        RecalculateProjectRiskJob::dispatch($project);
    }

    /**
     * <summary>
     *  Pause a project inside a transaction. No risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function pauseProject(Project $project): Project
    {
        return DB::transaction(fn() => $this->projectService->pauseProject($project));
    }

    /**
     * <summary>
     *  Resume a paused project inside a transaction. No risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function resumeProject(Project $project): Project
    {
        return DB::transaction(fn() => $this->projectService->resumeProject($project));
    }

    /**
     * <summary>
     *  Mark a project completed inside a transaction. No risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function completeProject(Project $project): Project
    {
        return DB::transaction(fn() => $this->projectService->completeProject($project));
    }

    /**
     * <summary>
     *  Reopen a completed project inside a transaction. No risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function reopenProject(Project $project): Project
    {
        return DB::transaction(fn() => $this->projectService->reopenProject($project));
    }

    /**
     * <summary>
     *  Archive a project inside a transaction. No risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function archiveProject(Project $project): Project
    {
        return DB::transaction(fn() => $this->projectService->archiveProject($project));
    }

    /**
     * <summary>
     *  Unarchive a project inside a transaction. No risk recalculation.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function unarchiveProject(Project $project): Project
    {
        return DB::transaction(fn() => $this->projectService->unarchiveProject($project));
    }
}
