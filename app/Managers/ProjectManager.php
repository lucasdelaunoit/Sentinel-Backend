<?php

namespace App\Managers;

use App\Jobs\RecalculateProjectRiskJob;
use App\Models\Project;
use App\Services\ProjectService;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectManager
{
    public function __construct(
        private readonly SkillCoverageService   $coverage,
        private readonly RiskCalculationService $risk,
        private readonly ProjectService         $projectService,
    ) {}

    public function getAgileProjects(Request $request): LengthAwarePaginator
    {
        return $this->projectService->getAgileProjects($request);
    }

    public function create(array $data): Project
    {
        $project = DB::transaction(fn() => Project::create($data));

        RecalculateProjectRiskJob::dispatch($project);

        return $project;
    }

    public function get(Project $project): Project
    {
        return $project->loadMissing([
            'employees.department',
            'skillRequirements.category',
            'simulations',
        ]);
    }

    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        if (array_intersect(array_keys($data), ['status', 'progress'])) {
            RecalculateProjectRiskJob::dispatch($project);
        }

        return $project->fresh();
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function getCoverage(Project $project): array
    {
        return $this->coverage->getCoverage($project);
    }

    public function getMetrics(Project $project): array
    {
        return [
            'bus_factor'  => $this->risk->computeBusFactor($project),
            'risk_score'  => $this->risk->computeRiskScore($project),
            'health'      => $this->risk->computeHealthScore($project),
            'redundancy'  => $this->coverage->getRedundancy($project),
        ];
    }

    public function attachEmployee(Project $project, int $employeeId): void
    {
        DB::transaction(function () use ($project, $employeeId) {
            $project->employees()->syncWithoutDetaching([$employeeId]);
        });

        RecalculateProjectRiskJob::dispatch($project);
    }

    public function detachEmployee(Project $project, int $employeeId): void
    {
        DB::transaction(function () use ($project, $employeeId) {
            $project->employees()->detach($employeeId);
        });

        RecalculateProjectRiskJob::dispatch($project);
    }

    public function attachSkill(Project $project, int $skillId, int $requiredLevel): void
    {
        DB::transaction(function () use ($project, $skillId, $requiredLevel) {
            $project->skillRequirements()->syncWithoutDetaching([
                $skillId => ['required_level' => $requiredLevel],
            ]);
        });

        RecalculateProjectRiskJob::dispatch($project);
    }

    public function detachSkill(Project $project, int $skillId): void
    {
        DB::transaction(function () use ($project, $skillId) {
            $project->skillRequirements()->detach($skillId);
        });

        RecalculateProjectRiskJob::dispatch($project);
    }
}
