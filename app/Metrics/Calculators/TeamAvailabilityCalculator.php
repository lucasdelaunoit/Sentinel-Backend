<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Models\User;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Team availability metric — KPI exposes total / available / absent /
 * critical-absent counts; detail lists every currently absent user with
 * criticality + at-risk projects whose coverage degraded as a result.
 */
class TeamAvailabilityCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
        private readonly RiskCalculationService $riskCalculationService,
    ) {}

    /**
     * <summary>
     *  Headline KPI — today's headcount picture with critical-absence count.
     * </summary>
     *
     * @return array{total: int, available: int, absent: int, critical_absent: int, insight: string}
     */
    public function kpi(): array
    {
        $today = Carbon::today();
        $total = User::count();

        $absentIds = User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id');

        $absent = $absentIds->count();
        $available = $total - $absent;

        $criticalAbsent = $absent > 0
            ? DB::table('project_users')
                ->join('projects', 'project_users.project_id', '=', 'projects.id')
                ->whereIn('project_users.user_id', $absentIds)
                ->whereNotNull('projects.started_at')
                ->whereDate('projects.started_at', '<=', now())
                ->whereNull('projects.paused_at')
                ->whereNull('projects.completed_at')
                ->whereNull('projects.archived_at')
                ->where('projects.bus_factor', '<=', 1)
                ->distinct()
                ->count('project_users.user_id')
            : 0;

        $insight = match (true) {
            $criticalAbsent > 0 => "{$criticalAbsent} critical employee" . ($criticalAbsent > 1 ? 's' : '') . ' absent',
            $absent > 0 => "{$absent} employee" . ($absent > 1 ? 's' : '') . ' absent',
            default => 'Fully operational',
        };

        return [
            'total' => $total,
            'available' => $available,
            'absent' => $absent,
            'critical_absent' => $criticalAbsent,
            'insight' => $insight,
        ];
    }

    /**
     * <summary>
     *  Drilldown — absent users (with criticality + projects + skills) and the
     *  list of active projects whose coverage degraded because of those absences.
     * </summary>
     *
     * @return array{absent_employees: array<int, array>, at_risk_projects: array<int, array>}
     */
    public function detail(): array
    {
        $today = Carbon::today();
        $absent = User::with(['absences', 'skills.category', 'projects'])
            ->get()
            ->filter(fn($u) => $u->absences->some(
                fn($a) => Carbon::parse($a->start_date)->lte($today)
                    && Carbon::parse($a->end_date)->gte($today)
            ));

        $absentDetail = $absent->map(function ($user) {
            $criticality = $this->riskCalculationService->computeUserCriticality($user);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'title' => $user->title,
                'is_critical' => $criticality['silo_count'] > 0 || $criticality['unique_skills'] > 0,
                'projects' => $user->projects->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'bus_factor' => $p->bus_factor,
                ])->values()->all(),
                'skills' => $user->skills->map(fn($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'level' => $s->pivot->level,
                ])->values()->all(),
                'criticality' => $criticality,
            ];
        })->values()->all();

        $absentIds = $absent->pluck('id')->all();
        $atRiskProjects = [];

        if (!empty($absentIds)) {
            $activeProjects = Project::active()
                ->whereHas('users', fn($q) => $q->whereIn('users.id', $absentIds))
                ->with(['skillRequirements', 'users.skills', 'users.absences'])
                ->get();

            foreach ($activeProjects as $project) {
                $baseline = $this->coverageService->getCoverage($project);
                $withAbsence = $this->coverageService->getCoverageAfterAbsence($project, $absentIds);

                $degraded = collect($withAbsence)->filter(
                    fn($s, $id) => $s['status'] !== ($baseline[$id]['status'] ?? $s['status'])
                );

                if ($degraded->isNotEmpty()) {
                    $atRiskProjects[] = [
                        'id' => $project->id,
                        'name' => $project->name,
                        'degraded_skills' => $degraded->map(fn($s, $id) => [
                            'skill_id' => $s['skill_id'],
                            'skill_name' => $s['skill_name'],
                            'before' => $baseline[$id]['status'],
                            'after' => $s['status'],
                        ])->values()->all(),
                    ];
                }
            }
        }

        return [
            'absent_employees' => $absentDetail,
            'at_risk_projects' => $atRiskProjects,
        ];
    }
}
