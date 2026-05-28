<?php

namespace App\Managers;

use App\DTO\Stats\ProjectsStats;
use App\DTO\Stats\ProjectStats;
use App\Jobs\RecalculateProjectRiskJob;
use App\Metrics\Calculators\AbsenceImpactCalculator;
use App\Metrics\Calculators\BusFactorCalculator;
use App\Metrics\Calculators\DeadlineCountdownCalculator;
use App\Metrics\Calculators\DeadlinePressureCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Metrics\Calculators\KnowledgeCoverageCalculator;
use App\Metrics\Calculators\ProjectsTotalCalculator;
use App\Metrics\Calculators\TeamAvailabilityCalculator;
use App\Metrics\Scales\FragilityScale;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\SkillCoverageService;
use App\Support\QueryParams;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProjectManager
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
        private readonly ProjectService $projectService,
        private readonly FragilityCalculator $fragilityCalculator,
        private readonly BusFactorCalculator $busFactorCalculator,
        private readonly TeamAvailabilityCalculator $teamAvailabilityCalculator,
        private readonly KnowledgeCoverageCalculator $knowledgeCoverageCalculator,
        private readonly AbsenceImpactCalculator $absenceImpactCalculator,
        private readonly DeadlineCountdownCalculator $deadlineCountdownCalculator,
        private readonly ProjectsTotalCalculator $projectsTotalCalculator,
        private readonly DeadlinePressureCalculator $deadlinePressureCalculator,
    ) {}

    /**
     * <summary>
     *  Recompute fragility + team_availability + knowledge_coverage for a project and persist
     *  the cache columns + metric_snapshots history rows in a single transaction.
     *  Single writer — columns and snapshots can't drift because the same transaction owns both.
     * </summary>
     *
     * @param Project $project Target project
     * @return void
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function recalculateProjectMetrics(Project $project): void
    {
        $this->busFactorCalculator->forProject($project);
        $this->teamAvailabilityCalculator->forProject($project);
        $this->knowledgeCoverageCalculator->forProject($project);
        $this->absenceImpactCalculator->forProject($project);
        $this->fragilityCalculator->forProject($project);
        $this->deadlineCountdownCalculator->forProject($project);
    }

    /**
     * <summary>
     *  Capture the org-scope projects-stats snapshots. Each Calculator owns its own transaction.
     *  Not wired to a trigger yet — call from a future cron / org-recalc job.
     * </summary>
     *
     * @return void
     * @throws Throwable When the underlying DB transactions fail
     */
    public function captureProjectsStatsSnapshots(): void
    {
        $this->projectsTotalCalculator->forOrg();
        $this->fragilityCalculator->forOrg(); // writes ProjectsAvgFragility + ProjectsFragileCount + DashboardWorstFragility
        $this->deadlinePressureCalculator->forOrg();
    }

    /**
     * <summary>
     *  Assemble the typed ProjectsStats DTO for GET /projects/stats.
     *  Orchestrates ProjectService — one Service call per metric.
     * </summary>
     *
     * @return ProjectsStats total, avg_fragility, fragile_count, deadline_pressure
     */
    public function getProjectsStats(): ProjectsStats
    {
        return new ProjectsStats(
            total: $this->projectService->getProjectsTotalStat(),
            avgFragility: $this->projectService->getProjectsAvgFragilityStat(),
            fragileCount: $this->projectService->getProjectsFragileCountStat(),
            deadlinePressure: $this->projectService->getProjectsDeadlinePressureStat(),
        );
    }

    /**
     * <summary>
     *  Assemble the typed ProjectStats DTO for GET /projects/{project}/stats.
     *  Orchestrates ProjectService — one Service call per metric.
     * </summary>
     *
     * @param Project $project Target project
     * @return ProjectStats fragility, team_availability, knowledge_coverage, deadline_countdown
     */
    public function getProjectStats(Project $project): ProjectStats
    {
        return new ProjectStats(
            fragility: $this->projectService->getProjectFragilityStat($project),
            teamAvailability: $this->projectService->getProjectTeamAvailabilityStat($project),
            knowledgeCoverage: $this->projectService->getProjectKnowledgeCoverageStat($project),
            deadlineCountdown: $this->projectService->getProjectDeadlineCountdownStat($project),
        );
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
     *  Create a new project inside a transaction. Optionally attaches initial team members and
     *  skill requirements in the same transaction so the project lands fully wired.
     *  Orchestrates ProjectService for: row insert + user pivot batch + skill-requirement pivot batch.
     *
     *  TODO(recalc): decide later whether to dispatch RecalculateProjectRiskJob here.
     *    - Skip when no users + no skills attached (recalc would be a no-op).
     *    - Dispatch when at least one of user_ids / skill_requirements is present.
     *    Pending the new FragilityService landing — wire job dispatch then.
     * </summary>
     *
     * @param array $data Validated payload. Keys:
     *                    name, description, started_at, deadline,
     *                    user_ids?: int[],
     *                    skill_requirements?: array<int, array{skill_id:int, required_level:int}>
     * @return Project Newly created project with users.department + skillRequirements.category loaded
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function createProject(array $data): Project
    {
        $userIds      = $data['user_ids'] ?? [];
        $requirements = $data['skill_requirements'] ?? [];
        $projectData  = collect($data)->except(['user_ids', 'skill_requirements'])->all();

        $project = DB::transaction(function () use ($projectData, $userIds, $requirements) {
            $project = $this->projectService->createProject($projectData);
            $this->projectService->attachUsersToProject($project, $userIds);
            $this->projectService->attachSkillsToProject($project, $requirements);

            return $project;
        });

        RecalculateProjectRiskJob::dispatch($project);

        return $project->load(['users.department', 'skillRequirements.category']);
    }

    /**
     * <summary>
     *  Retrieve a project with its detail-view relations eager-loaded.
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Project with users.department and skillRequirements loaded
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
        $fresh = DB::transaction(fn() => $this->projectService->updateProject($project, $data));
        RecalculateProjectRiskJob::dispatch($fresh);
        return $fresh;
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
     *  Assemble project-level metrics: bus_factor, fragility_raw + tier, redundancy.
     * </summary>
     *
     * @param Project $project Target project
     * @return array bus_factor, fragility_raw, fragility, redundancy
     */
    public function getProjectMetrics(Project $project): array
    {
        $fragilityRaw = $this->fragilityCalculator->calculate($project);

        return [
            'bus_factor' => $this->busFactorCalculator->calculate($project),
            'fragility_raw' => $fragilityRaw,
            'fragility' => FragilityScale::fromRaw($fragilityRaw)->value,
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
     *  Pause a project inside a transaction. Dispatches risk recalculation (progress freezes).
     * </summary>
     *
     * @param Project $project Target project
     * @return Project Refreshed project
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function pauseProject(Project $project): Project
    {
        $fresh = DB::transaction(fn() => $this->projectService->pauseProject($project));
        RecalculateProjectRiskJob::dispatch($fresh);
        return $fresh;
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
        $fresh = DB::transaction(fn() => $this->projectService->resumeProject($project));
        RecalculateProjectRiskJob::dispatch($fresh);
        return $fresh;
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
        $fresh = DB::transaction(fn() => $this->projectService->completeProject($project));
        RecalculateProjectRiskJob::dispatch($fresh);
        return $fresh;
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
        $fresh = DB::transaction(fn() => $this->projectService->reopenProject($project));
        RecalculateProjectRiskJob::dispatch($fresh);
        return $fresh;
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

    /**
     * <summary>
     *  Per-project knowledge-coverage breakdown — skill requirement rows with holders,
     *  active_holders_count, status (uncovered/silo/covered), max_level, team_size.
     * </summary>
     *
     * @param Project $project Target project
     * @return array Knowledge-coverage rows keyed numerically
     */
    public function getProjectKnowledgeCoverage(Project $project): array
    {
        return $this->projectService->getProjectKnowledgeCoverage($project);
    }

    /**
     * <summary>
     *  Per-project competency radar — one row per SkillCategory with value (0–100) and target.
     * </summary>
     *
     * @param Project $project Target project
     * @return array Radar rows keyed numerically
     */
    public function getProjectCompetencyRadar(Project $project): array
    {
        return $this->projectService->getProjectCompetencyRadar($project);
    }
}
