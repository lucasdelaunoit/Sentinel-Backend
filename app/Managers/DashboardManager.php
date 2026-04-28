<?php

namespace App\Managers;

use App\Models\Employee;
use App\Models\Project;
use App\Models\SkillCategory;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardManager
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
        private readonly RiskCalculationService $riskCalculationService,
    ) {}

    // ─── Stats summary (lightweight — no coverage matrix, no detail) ──────────
    public function getTodayStats(): array
    {
        return [
            'projects_at_risk' => $this->projectsAtRiskStats(),
            'knowledge_coverage' => $this->knowledgeCoverageStats(),
            'team_availability' => $this->teamAvailabilityStats(),
            'absence_impact' => $this->absenceImpactStats(),
        ];
    }

    // ─── Stat detail (heavier — called on modal open only) ───────────────────

    public function getProjectsAtRiskDetail(): array
    {
        $projects = Project::where('status', 'active')
            ->where('risk_score', '>', 50)
            ->with(['skillRequirements', 'employees.skills', 'employees.leaves'])
            ->orderByDesc('risk_score')
            ->get();

        $mapProject = function (Project $p) {
            $matrix = $this->coverageService->getCoverage($p);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'risk_score' => $p->risk_score,
                'bus_factor' => $p->bus_factor,
                'health' => $p->health,
                'missing_skills' => collect($matrix)
                    ->where('status', 'uncovered')
                    ->map(fn($s) => ['skill_id' => $s['skill_id'], 'skill_name' => $s['skill_name']])
                    ->values()->all(),
                'siloed_skills'  => collect($matrix)
                    ->where('status', 'siloed')
                    ->map(fn($s) => [
                        'skill_id' => $s['skill_id'],
                        'skill_name' => $s['skill_name'],
                        'owner' => $s['employees'][0] ?? null,
                    ])
                    ->values()->all(),
            ];
        };

        return [
            'critical' => $projects->filter(fn($p) => $p->risk_score > 75)->map($mapProject)->values()->all(),
            'unstable' => $projects->filter(fn($p) => $p->risk_score > 50 && $p->risk_score <= 75)->map($mapProject)->values()->all(),
        ];
    }

    public function getKnowledgeCoverageDetail(): array
    {
        $projects  = Project::where('status', 'active')
            ->with(['skillRequirements.category', 'employees.skills', 'employees.leaves'])
            ->get();

        $byCategory = [];

        foreach ($projects as $project) {
            $matrix = $this->coverageService->getCoverage($project);
            $catLookup = $project->skillRequirements->keyBy('id');

            foreach ($matrix as $skillId => $skill) {
                $cat = $catLookup[$skillId] ?? null;
                $catId = $cat?->category?->id   ?? 0;
                $catName = $cat?->category?->name ?? 'Uncategorized';

                if (!isset($byCategory[$catId])) {
                    $byCategory[$catId] = [
                        'category_id' => $catId,
                        'category_name' => $catName,
                        'total' => 0,
                        'safe' => 0,
                        'siloed' => 0,
                        'uncovered' => 0,
                        'siloed_skills' => [],
                        'uncovered_skills' => [],
                    ];
                }

                $byCategory[$catId]['total']++;

                if ($skill['status'] === 'safe') {
                    $byCategory[$catId]['safe']++;
                } elseif ($skill['status'] === 'siloed') {
                    $byCategory[$catId]['siloed']++;
                    $byCategory[$catId]['siloed_skills'][] = [
                        'skill_id' => $skill['skill_id'],
                        'skill_name' => $skill['skill_name'],
                        'owner' => $skill['employees'][0] ?? null,
                    ];
                } elseif ($skill['status'] === 'uncovered') {
                    $byCategory[$catId]['uncovered']++;
                    $byCategory[$catId]['uncovered_skills'][] = [
                        'skill_id' => $skill['skill_id'],
                        'skill_name' => $skill['skill_name'],
                    ];
                }
            }
        }

        $categories = array_map(function ($cat) {
            $cat['coverage_pct'] = $cat['total'] > 0
                ? (int) round(($cat['safe'] / $cat['total']) * 100)
                : 100;
            return $cat;
        }, array_values($byCategory));

        usort($categories, fn($a, $b) => $a['coverage_pct'] <=> $b['coverage_pct']);

        $mostFragile = !empty($categories) && $categories[0]['coverage_pct'] < 100
            ? "Most fragile: {$categories[0]['category_name']} ({$categories[0]['coverage_pct']}%)"
            : null;

        return [
            'categories' => $categories,
            'most_fragile' => $mostFragile,
        ];
    }

    public function getTeamAvailabilityDetail(): array
    {
        $today  = Carbon::today();
        $absent = Employee::with(['leaves', 'skills.category', 'projects'])
            ->get()
            ->filter(fn($e) => $e->leaves->some(
                fn($l) => Carbon::parse($l->start_date)->lte($today)
                    && Carbon::parse($l->end_date)->gte($today)
            ));

        $absentDetail = $absent->map(function ($emp) {
            $criticality = $this->riskCalculationService->computeEmployeeCriticality($emp);

            return [
                'id' => $emp->id,
                'name' => $emp->name,
                'title' => $emp->title,
                'is_critical' => $criticality['silo_count'] > 0 || $criticality['unique_skills'] > 0,
                'projects' => $emp->projects->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'bus_factor' => $p->bus_factor,
                ])->values()->all(),
                'skills' => $emp->skills->map(fn($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'level' => $s->pivot->level,
                ])->values()->all(),
                'criticality' => $criticality,
            ];
        })->values()->all();

        // Projects at risk because of today's absences
        $absentIds = $absent->pluck('id')->all();
        $atRiskProjects = [];

        if (!empty($absentIds)) {
            $activeProjects = Project::where('status', 'active')
                ->whereHas('employees', fn($q) => $q->whereIn('employees.id', $absentIds))
                ->with(['skillRequirements', 'employees.skills', 'employees.leaves'])
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

    public function getAbsenceImpactDetail(): array
    {
        $today = Carbon::today();
        $absentIds = Employee::whereHas('leaves', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();

        if (empty($absentIds)) {
            return ['uncovered_skills' => []];
        }

        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'employees.skills', 'employees.leaves'])
            ->get();

        $uncoveredSkills = [];

        foreach ($projects as $project) {
            $baseline    = $this->coverageService->getCoverage($project);
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

    // ─── Private: lightweight stats builders ─────────────────────────────────

    private function projectsAtRiskStats(): array
    {
        $critical = Project::where('status', 'active')->where('risk_score', '>', 75)->count();
        $unstable = Project::where('status', 'active')->whereBetween('risk_score', [51, 75])->count();
        $total = $critical + $unstable;

        $parts = [];
        if ($critical > 0) $parts[] = "{$critical} critical";
        if ($unstable > 0) $parts[] = "{$unstable} unstable";

        return [
            'value' => $total,
            'insight' => empty($parts) ? "All projects healthy" : implode(' · ', $parts),
            'severity' => $critical > 0 ? 'critical' : ($unstable > 0 ? 'warning' : 'ok'),
        ];
    }

    private function knowledgeCoverageStats(): array
    {
        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'employees.skills', 'employees.leaves'])
            ->get();

        $total = 0;
        $safe = 0;
        $underCovered = 0;

        foreach ($projects as $project) {
            foreach ($this->coverageService->getCoverage($project) as $skill) {
                $total++;
                if ($skill['status'] === 'safe') {
                    $safe++;
                } else {
                    $underCovered++;
                }
            }
        }

        $pct = $total > 0 ? (int) round(($safe / $total) * 100) : 100;

        return [
            'value' => $pct,
            'insight' => $underCovered > 0
                ? "{$underCovered} skill" . ($underCovered > 1 ? 's' : '') . " under-covered"
                : "All skills covered",
            'severity' => $pct < 50 ? 'critical' : ($pct < 75 ? 'warning' : 'ok'),
        ];
    }

    private function teamAvailabilityStats(): array
    {
        $today = Carbon::today();
        $total = Employee::count();

        $absentIds = Employee::whereHas('leaves', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id');

        $absentCount = $absentIds->count();
        $available = $total - $absentCount;

        // "Critical" = absent employee on a project whose bus_factor is already ≤ 1
        $criticalCount = $absentCount > 0
            ? DB::table('project_employees')
                ->join('projects', 'project_employees.project_id', '=', 'projects.id')
                ->whereIn('project_employees.employee_id', $absentIds)
                ->where('projects.status', 'active')
                ->where('projects.bus_factor', '<=', 1)
                ->distinct()
                ->count('project_employees.employee_id')
            : 0;

        $insight = match (true) {
            $criticalCount > 0 => "{$criticalCount} critical employee" . ($criticalCount > 1 ? 's' : '') . " absent",
            $absentCount > 0 => "{$absentCount} employee" . ($absentCount > 1 ? 's' : '') . " absent",
            default => "Fully operational",
        };

        return [
            'value' => "{$available}/{$total}",
            'available' => $available,
            'total' => $total,
            'insight' => $insight,
            'severity' => $criticalCount > 0 ? 'critical' : ($absentCount > 0 ? 'warning' : 'ok'),
        ];
    }

    private function absenceImpactStats(): array
    {
        $today = Carbon::today();
        $absentIds = Employee::whereHas('leaves', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();

        if (empty($absentIds)) {
            return ['value' => 0, 'insight' => "No impact from absences", 'severity' => 'ok'];
        }

        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'employees.skills', 'employees.leaves'])
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
            'value' => $count,
            'insight' => $count > 0
                ? "skill" . ($count > 1 ? 's' : '') . " became uncovered"
                : "No impact from absences",
            'severity' => $count > 0 ? 'critical' : 'ok',
        ];
    }
}
