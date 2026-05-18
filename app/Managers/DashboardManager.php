<?php

namespace App\Managers;

use App\Models\Project;
use App\Models\SkillCategory;
use App\Models\User;
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

    public function getTodayStats(): array
    {
        return [
            'fragile_projects'   => $this->fragileProjectsStats(),
            'knowledge_coverage' => $this->knowledgeCoverageStats(),
            'team_availability'  => $this->teamAvailabilityStats(),
            'absence_impact'     => $this->absenceImpactStats(),
        ];
    }

    public function getProjectsAtRiskDetail(): array
    {
        $projects = Project::where('status', 'active')
            ->where('fragility_raw', '>', 50)
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->orderByDesc('fragility_raw')
            ->get();

        $mapProject = function (Project $p) {
            $matrix = $this->coverageService->getCoverage($p);

            return [
                'id'             => $p->id,
                'name'           => $p->name,
                'fragility_raw'  => $p->fragility_raw,
                'fragility'      => RiskCalculationService::fragilityTier($p->fragility_raw),
                'bus_factor'     => $p->bus_factor,
                'trajectory_raw' => $p->trajectory_raw,
                'trajectory'     => RiskCalculationService::trajectoryTier($p->trajectory_raw),
                'missing_skills' => collect($matrix)
                    ->where('status', 'uncovered')
                    ->map(fn($s) => ['skill_id' => $s['skill_id'], 'skill_name' => $s['skill_name']])
                    ->values()->all(),
                'siloed_skills'  => collect($matrix)
                    ->where('status', 'siloed')
                    ->map(fn($s) => [
                        'skill_id'   => $s['skill_id'],
                        'skill_name' => $s['skill_name'],
                        'owner'      => $s['employees'][0] ?? null,
                    ])
                    ->values()->all(),
            ];
        };

        return [
            'critical' => $projects->filter(fn($p) => $p->fragility_raw > 75)->map($mapProject)->values()->all(),
            'unstable' => $projects->filter(fn($p) => $p->fragility_raw > 50 && $p->fragility_raw <= 75)->map($mapProject)->values()->all(),
        ];
    }

    public function getKnowledgeCoverageDetail(): array
    {
        $projects = Project::where('status', 'active')
            ->with(['skillRequirements.category', 'users.skills', 'users.absences'])
            ->get();

        $byCategory = [];

        foreach ($projects as $project) {
            $matrix    = $this->coverageService->getCoverage($project);
            $catLookup = $project->skillRequirements->keyBy('id');

            foreach ($matrix as $skillId => $skill) {
                $cat     = $catLookup[$skillId] ?? null;
                $catId   = $cat?->category?->id   ?? 0;
                $catName = $cat?->category?->name ?? 'Uncategorized';

                if (!isset($byCategory[$catId])) {
                    $byCategory[$catId] = [
                        'category_id'       => $catId,
                        'category_name'     => $catName,
                        'total'             => 0,
                        'safe'              => 0,
                        'siloed'            => 0,
                        'uncovered'         => 0,
                        'siloed_skills'     => [],
                        'uncovered_skills'  => [],
                    ];
                }

                $byCategory[$catId]['total']++;

                if ($skill['status'] === 'safe') {
                    $byCategory[$catId]['safe']++;
                } elseif ($skill['status'] === 'siloed') {
                    $byCategory[$catId]['siloed']++;
                    $byCategory[$catId]['siloed_skills'][] = [
                        'skill_id'   => $skill['skill_id'],
                        'skill_name' => $skill['skill_name'],
                        'owner'      => $skill['employees'][0] ?? null,
                    ];
                } elseif ($skill['status'] === 'uncovered') {
                    $byCategory[$catId]['uncovered']++;
                    $byCategory[$catId]['uncovered_skills'][] = [
                        'skill_id'   => $skill['skill_id'],
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
            'categories'   => $categories,
            'most_fragile' => $mostFragile,
        ];
    }

    public function getTeamAvailabilityDetail(): array
    {
        $today  = Carbon::today();
        $absent = User::with(['absences', 'skills.category', 'projects'])
            ->get()
            ->filter(fn($u) => $u->absences->some(
                fn($a) => Carbon::parse($a->start_date)->lte($today)
                    && Carbon::parse($a->end_date)->gte($today)
            ));

        $absentDetail = $absent->map(function ($user) {
            $criticality = $this->riskCalculationService->computeUserCriticality($user);

            return [
                'id'          => $user->id,
                'name'        => $user->name,
                'title'       => $user->title,
                'is_critical' => $criticality['silo_count'] > 0 || $criticality['unique_skills'] > 0,
                'projects'    => $user->projects->map(fn($p) => [
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'bus_factor' => $p->bus_factor,
                ])->values()->all(),
                'skills'      => $user->skills->map(fn($s) => [
                    'id'    => $s->id,
                    'name'  => $s->name,
                    'level' => $s->pivot->level,
                ])->values()->all(),
                'criticality' => $criticality,
            ];
        })->values()->all();

        $absentIds      = $absent->pluck('id')->all();
        $atRiskProjects = [];

        if (!empty($absentIds)) {
            $activeProjects = Project::where('status', 'active')
                ->whereHas('users', fn($q) => $q->whereIn('users.id', $absentIds))
                ->with(['skillRequirements', 'users.skills', 'users.absences'])
                ->get();

            foreach ($activeProjects as $project) {
                $baseline    = $this->coverageService->getCoverage($project);
                $withAbsence = $this->coverageService->getCoverageAfterAbsence($project, $absentIds);

                $degraded = collect($withAbsence)->filter(
                    fn($s, $id) => $s['status'] !== ($baseline[$id]['status'] ?? $s['status'])
                );

                if ($degraded->isNotEmpty()) {
                    $atRiskProjects[] = [
                        'id'               => $project->id,
                        'name'             => $project->name,
                        'degraded_skills'  => $degraded->map(fn($s, $id) => [
                            'skill_id'   => $s['skill_id'],
                            'skill_name' => $s['skill_name'],
                            'before'     => $baseline[$id]['status'],
                            'after'      => $s['status'],
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
        $today     = Carbon::today();
        $absentIds = User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();

        if (empty($absentIds)) {
            return ['uncovered_skills' => []];
        }

        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $uncoveredSkills = [];

        foreach ($projects as $project) {
            $baseline    = $this->coverageService->getCoverage($project);
            $withAbsence = $this->coverageService->getCoverageAfterAbsence($project, $absentIds);

            foreach ($withAbsence as $skillId => $simSkill) {
                $baseStatus = $baseline[$skillId]['status'] ?? 'uncovered';

                if ($simSkill['status'] === 'uncovered' && $baseStatus !== 'uncovered') {
                    $uncoveredSkills[] = [
                        'skill_id'              => $skillId,
                        'skill_name'            => $simSkill['skill_name'],
                        'required_by_project'   => ['id' => $project->id, 'name' => $project->name],
                        'previously_covered_by' => $baseline[$skillId]['employees'],
                        'before_status'         => $baseStatus,
                    ];
                }
            }
        }

        return ['uncovered_skills' => $uncoveredSkills];
    }

    private function fragileProjectsStats(): array
    {
        $critical = Project::where('status', 'active')->where('fragility_raw', '>', 75)->count();
        $unstable = Project::where('status', 'active')->whereBetween('fragility_raw', [51, 75])->count();
        $total    = $critical + $unstable;

        $parts = [];
        if ($critical > 0) $parts[] = "{$critical} critical";
        if ($unstable > 0) $parts[] = "{$unstable} unstable";

        return [
            'value'    => $total,
            'insight'  => empty($parts) ? "All projects healthy" : implode(' · ', $parts),
            'severity' => $critical > 0 ? 'critical' : ($unstable > 0 ? 'warning' : 'ok'),
        ];
    }

    private function knowledgeCoverageStats(): array
    {
        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $total        = 0;
        $safe         = 0;
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
            'value'    => $pct,
            'insight'  => $underCovered > 0
                ? "{$underCovered} skill" . ($underCovered > 1 ? 's' : '') . " under-covered"
                : "All skills covered",
            'severity' => $pct < 50 ? 'critical' : ($pct < 75 ? 'warning' : 'ok'),
        ];
    }

    private function teamAvailabilityStats(): array
    {
        $today = Carbon::today();
        $total = User::count();

        $absentIds = User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id');

        $absentCount = $absentIds->count();
        $available   = $total - $absentCount;

        $criticalCount = $absentCount > 0
            ? DB::table('project_users')
                ->join('projects', 'project_users.project_id', '=', 'projects.id')
                ->whereIn('project_users.user_id', $absentIds)
                ->where('projects.status', 'active')
                ->where('projects.bus_factor', '<=', 1)
                ->distinct()
                ->count('project_users.user_id')
            : 0;

        $insight = match (true) {
            $criticalCount > 0 => "{$criticalCount} critical employee" . ($criticalCount > 1 ? 's' : '') . " absent",
            $absentCount > 0   => "{$absentCount} employee" . ($absentCount > 1 ? 's' : '') . " absent",
            default            => "Fully operational",
        };

        return [
            'value'     => "{$available}/{$total}",
            'available' => $available,
            'total'     => $total,
            'insight'   => $insight,
            'severity'  => $criticalCount > 0 ? 'critical' : ($absentCount > 0 ? 'warning' : 'ok'),
        ];
    }

    private function absenceImpactStats(): array
    {
        $today     = Carbon::today();
        $absentIds = User::whereHas('absences', fn($q) =>
            $q->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)
        )->pluck('id')->all();

        if (empty($absentIds)) {
            return ['value' => 0, 'insight' => "No impact from absences", 'severity' => 'ok'];
        }

        $projects = Project::where('status', 'active')
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $count = 0;

        foreach ($projects as $project) {
            $baseline    = $this->coverageService->getCoverage($project);
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
            'value'    => $count,
            'insight'  => $count > 0
                ? "skill" . ($count > 1 ? 's' : '') . " became uncovered"
                : "No impact from absences",
            'severity' => $count > 0 ? 'critical' : 'ok',
        ];
    }
}
