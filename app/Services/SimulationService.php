<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Simulation;

class SimulationService
{
    public function __construct(
        private readonly SkillCoverageService  $coverage,
        private readonly RiskCalculationService $risk,
    ) {}

    public function run(Simulation $simulation): array
    {
        $simulation->loadMissing(['project', 'absentEmployees']);

        $project     = $simulation->project;
        $absentIds   = $simulation->absentEmployees->pluck('id')->all();

        return $this->computeImpact($project, $absentIds);
    }

    public function computeImpact(Project $project, array $absentEmployeeIds): array
    {
        $baseline    = $this->coverage->getCoverage($project);
        $simulated   = $this->coverage->getCoverageAfterAbsence($project, $absentEmployeeIds);

        $diff = [];
        foreach ($simulated as $skillId => $simSkill) {
            $before = $baseline[$skillId]['status'] ?? 'uncovered';
            $after  = $simSkill['status'];

            if ($before !== $after) {
                $diff[$skillId] = ['before' => $before, 'after' => $after, 'skill_name' => $simSkill['skill_name']];
            }
        }

        $originalMetrics = [
            'bus_factor'  => $this->risk->computeBusFactor($project),
            'risk_score'  => $this->risk->computeRiskScore($project),
            'health'      => $this->risk->computeHealthScore($project),
        ];

        // Temporarily inject absences into a transient project snapshot for metrics
        $simulatedBusFactor = $this->computeSimulatedBusFactor($simulated);
        $simulatedRisk      = $this->computeSimulatedRiskScore($project, $simulated, $simulatedBusFactor);
        $simulatedHealth    = round((100 - $simulatedRisk) * 0.7 + ($project->progress ?? 0) * 0.3, 2);

        return [
            'project_id'       => $project->id,
            'absent_employees' => $absentEmployeeIds,
            'original_metrics' => $originalMetrics,
            'simulated_metrics' => [
                'bus_factor'  => $simulatedBusFactor,
                'risk_score'  => $simulatedRisk,
                'health'      => $simulatedHealth,
            ],
            'coverage_diff'         => $diff,
            'newly_uncovered_count' => collect($diff)->where('after', 'uncovered')->count(),
            'newly_siloed_count'    => collect($diff)->where('after', 'siloed')->count(),
        ];
    }

    private function computeSimulatedBusFactor(array $matrix): int
    {
        $covered = collect($matrix)->filter(fn($s) => count($s['employees']) > 0);

        if ($covered->isEmpty()) return 0;

        return (int) $covered->min(fn($s) => count($s['employees']));
    }

    private function computeSimulatedRiskScore(Project $project, array $matrix, int $busFactor): float
    {
        $total      = count($matrix);
        if ($total === 0) return 0.0;

        $uncovered  = collect($matrix)->where('status', 'uncovered')->count();
        $siloed     = collect($matrix)->where('status', 'siloed')->count();

        $busRisk       = $busFactor >= 5 ? 0 : max(0, 100 - $busFactor * 20);
        $uncoveredRisk = ($uncovered / $total) * 100;
        $siloRisk      = ($siloed / $total) * 100;

        return round($busRisk * 0.35 + $uncoveredRisk * 0.30 + $siloRisk * 0.20, 2);
    }
}
