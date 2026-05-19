<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Models\User;
use App\Services\SkillCoverageService;
use Carbon\Carbon;

/**
 * Absence-impact metric — counts skills made newly uncovered by today's
 * active absences. Detail enumerates each such skill with the project
 * requiring it and the people who previously covered it.
 */
class AbsenceImpactCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
    ) {}

    /**
     * <summary>
     *  Headline KPI — number of skills that flipped to 'uncovered' because of active absences.
     * </summary>
     *
     * @return array{raw: int, insight: string}
     */
    public function kpi(): array
    {
        $absentIds = $this->todaysAbsentIds();

        if (empty($absentIds)) {
            return ['raw' => 0, 'insight' => 'No impact from absences'];
        }

        $count = 0;
        foreach ($this->projectsWithCoverageDeps() as $project) {
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

    /**
     * <summary>
     *  Drilldown — per-skill listing of coverage flips caused by today's absences,
     *  with the requiring project and the previously-covering employees.
     * </summary>
     *
     * @return array{uncovered_skills: array<int, array>}
     */
    public function detail(): array
    {
        $absentIds = $this->todaysAbsentIds();

        if (empty($absentIds)) {
            return ['uncovered_skills' => []];
        }

        $uncoveredSkills = [];

        foreach ($this->projectsWithCoverageDeps() as $project) {
            $baseline = $this->coverageService->getCoverage($project);
            $withAbsence = $this->coverageService->getCoverageAfterAbsence($project, $absentIds);

            foreach ($withAbsence as $skillId => $simSkill) {
                $baseStatus = $baseline[$skillId]['status'] ?? 'uncovered';

                if ($simSkill['status'] === 'uncovered' && $baseStatus !== 'uncovered') {
                    $uncoveredSkills[] = [
                        'skill_id' => $skillId,
                        'skill_name' => $simSkill['skill_name'],
                        'required_by_project' => ['id' => $project->id, 'name' => $project->name],
                        'previously_covered_by' => $baseline[$skillId]['employees'],
                        'before_status' => $baseStatus,
                    ];
                }
            }
        }

        return ['uncovered_skills' => $uncoveredSkills];
    }

    /** @return array<int, int> */
    private function todaysAbsentIds(): array
    {
        $today = Carbon::today();
        return User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();
    }

    private function projectsWithCoverageDeps()
    {
        return Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();
    }
}
