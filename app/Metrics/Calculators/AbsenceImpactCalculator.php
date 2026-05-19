<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Models\User;
use App\Services\SkillCoverageService;
use Carbon\Carbon;

/**
 * Counts skills that become uncovered specifically because of today's
 * active absences — the simulation pipeline reused as a live KPI.
 */
class AbsenceImpactCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
    ) {}

    /**
     * @return array{raw: int, insight: string}
     */
    public function compute(): array
    {
        $today = Carbon::today();
        $absentIds = User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();

        if (empty($absentIds)) {
            return ['raw' => 0, 'insight' => 'No impact from absences'];
        }

        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $count = 0;

        foreach ($projects as $project) {
            $baseline = $this->coverageService->getCoverage($project);
            $withAbsence = $this->coverageService->getCoverageAfterAbsence($project, $absentIds);

            foreach ($withAbsence as $skillId => $simSkill) {
                if (
                    $simSkill['status'] === 'uncovered' &&
                    ($baseline[$skillId]['status'] ?? 'uncovered') !== 'uncovered'
                ) {
                    $count++;
                }
            }
        }

        return [
            'raw' => $count,
            'insight' => $count > 0
                ? "{$count} skill" . ($count > 1 ? 's' : '') . ' became uncovered'
                : 'No impact from absences',
        ];
    }
}
