<?php

namespace App\Managers;

use App\Metrics\AbsenceImpactScale;
use App\Metrics\Calculators\AbsenceImpactCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Metrics\Calculators\KnowledgeCoverageCalculator;
use App\Metrics\Calculators\TeamAvailabilityCalculator;
use App\Metrics\FragilityScale;
use App\Metrics\KnowledgeCoverageScale;
use App\Metrics\TeamAvailabilityScale;
use App\Models\Project;
use App\Models\SkillCategory;
use App\Models\User;
use App\Services\RiskCalculationService;
use App\Services\SkillCoverageService;
use App\Support\Stat;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardManager
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
        private readonly RiskCalculationService $riskCalculationService,
        private readonly FragilityCalculator $fragilityCalculator,
        private readonly KnowledgeCoverageCalculator $knowledgeCoverageCalculator,
        private readonly TeamAvailabilityCalculator $teamAvailabilityCalculator,
        private readonly AbsenceImpactCalculator $absenceImpactCalculator,
    ) {}

    /**
     * @return array<string, Stat>
     */
    public function getTodayStats(): array
    {
        return [
            'fragile_projects' => $this->fragileProjectsStat(),
            'knowledge_coverage' => $this->knowledgeCoverageStat(),
            'team_availability' => $this->teamAvailabilityStat(),
            'absence_impact' => $this->absenceImpactStat(),
        ];
    }

    public function getProjectsAtRiskDetail(): array
    {
        $projects = Project::active()
            ->where('fragility_raw', '>', 40)
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->orderByDesc('fragility_raw')
            ->get();

        $mapProject = function (Project $p) {
            $matrix = $this->coverageService->getCoverage($p);

            return [
                'id'             => $p->id,
                'name'           => $p->name,
                'fragility_raw'  => $p->fragility_raw,
                'fragility'      => FragilityScale::fromRaw($p->fragility_raw)->value,
                'bus_factor'     => $p->bus_factor,
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
            'critical' => $projects->filter(fn($p) => $p->fragility_raw > 60)->map($mapProject)->values()->all(),
            'unstable' => $projects->filter(fn($p) => $p->fragility_raw > 40 && $p->fragility_raw <= 60)->map($mapProject)->values()->all(),
        ];
    }

    public function getKnowledgeCoverageDetail(): array
    {
        $projects = Project::active()
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
            $activeProjects = Project::active()
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

        $projects = Project::active()
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

    /**
     * <summary>
     *  Headline fragility — worst active project's fragility score 0-100.
     *  Value label comes from FragilityScale tier; insight summarises tier counts.
     * </summary>
     */
    private function fragileProjectsStat(): Stat
    {
        $r = $this->fragilityCalculator->compute();
        return Stat::fromScale(FragilityScale::fromRaw($r['raw']), $r['raw'], $r['insight']);
    }

    /**
     * <summary>
     *  Org-wide knowledge coverage — % of required skills that are 'safe'.
     *  Displayed as "{pct}%"; tier severity from KnowledgeCoverageScale.
     * </summary>
     */
    private function knowledgeCoverageStat(): Stat
    {
        $r = $this->knowledgeCoverageCalculator->compute();
        return Stat::display(
            "{$r['raw']}%",
            $r['raw'],
            KnowledgeCoverageScale::fromRaw($r['raw']),
            $r['insight'],
        );
    }

    /**
     * <summary>
     *  Headcount available today — displayed as "{available}/{total}".
     *  Severity derives from critical-absence presence via TeamAvailabilityScale.
     * </summary>
     */
    private function teamAvailabilityStat(): Stat
    {
        $r = $this->teamAvailabilityCalculator->compute();
        return Stat::display(
            "{$r['available']}/{$r['total']}",
            $r['available'],
            TeamAvailabilityScale::fromCounts($r['absent'], $r['critical_absent']),
            $r['insight'],
        );
    }

    /**
     * <summary>
     *  Count of skills newly uncovered because of today's absences.
     *  Tier label from AbsenceImpactScale.
     * </summary>
     */
    private function absenceImpactStat(): Stat
    {
        $r = $this->absenceImpactCalculator->compute();
        return Stat::fromScale(AbsenceImpactScale::fromRaw($r['raw']), $r['raw'], $r['insight']);
    }
}
