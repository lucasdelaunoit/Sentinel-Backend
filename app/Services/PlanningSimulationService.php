<?php

namespace App\Services;

use App\Enums\AbsenceHalf;
use App\Metrics\Calculators\BusFactorCalculator;
use App\Metrics\Calculators\FragilityCalculator;
use App\Models\Absence;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * <summary>
 *  Absence what-if engine. Given a set of pending absences, produces the rich simulate
 *  payload: totals, per-user / per-project / per-skill impact, day-load, hotspots,
 *  cascading risks, warnings, comparison vs baseline. Pure read — persists
 *  nothing. Reuses SkillCoverageService for the per-project coverage matrix so business
 *  rules stay consistent with the rest of the app.
 * </summary>
 */
class PlanningSimulationService
{
    /** Memoized inclusive date expansions keyed by "start|end" — same span is expanded many times per simulate. */
    private array $dateCache = [];

    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly FragilityCalculator $fragility,
        private readonly BusFactorCalculator $busFactor,
        private readonly CalendarService $calendar,
        private readonly OrganizationSettingService $orgSettings,
        private readonly CompanyHolidayService $companyHolidays,
    ) {}

    public function simulate(array $absences, ?string $month = null): array
    {
        $month ??= !empty($absences) ? substr($absences[0]['start_date'], 0, 7) : Carbon::now()->format('Y-m');

        if (empty($absences)) {
            return $this->emptyResponse($month);
        }

        $absentUserIds = collect($absences)->pluck('user_id')->map(fn($v) => (int) $v)->unique()->values()->all();
        $usersById     = User::query()
            ->whereIn('id', $absentUserIds)
            ->with(['skills', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences'])
            ->get()
            ->keyBy('id');

        $allUsers = User::query()->with(['skills'])->get();
        $totalUsers = $allUsers->count();

        /* Real working-day count for the simulated month (org calendar + holidays). */
        [$yearNum, $monthNum] = array_map('intval', explode('-', $month));
        $setting     = $this->orgSettings->getOrganizationSetting();
        $holidays    = $this->companyHolidays->getCompanyHolidaysForMonth($yearNum, $monthNum);
        $workingDays = max(1, $this->calendar->countWorkingDays($yearNum, $monthNum, $setting, $holidays));

        /* Affected projects = union of absent users' project rosters */
        $projectIds = $usersById->flatMap(fn(User $u) => $u->projects->pluck('id'))->unique()->values();
        $projects   = Project::query()
            ->whereIn('id', $projectIds)
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $userDays = $this->computeUserDays($absences);
        $perProject = [];
        $skillAggregator = [];

        foreach ($projects as $project) {
            $info = $this->computeProjectImpact($project, $absentUserIds, $absences, $skillAggregator);
            $perProject[] = $info;
        }

        $perSkill = $this->buildSkillImpacts($skillAggregator);
        $perUser  = $this->buildUserImpacts($absences, $usersById, $allUsers, $perProject, $userDays, $workingDays);

        /* Day load counts everyone out of office in the scenario window: the simulated
           absences plus the accepted ones already in the database. */
        $acceptedAbsences = $this->acceptedAbsencesWithin($absences);
        $simulatedUserIds = array_map('strval', $absentUserIds);
        $perDay   = $this->buildDayLoad(array_merge($absences, $acceptedAbsences), $perSkill, $totalUsers);
        $hotspots = $this->buildHotspots($perDay, $perProject, $simulatedUserIds);
        $shifts   = $this->buildShifts($perSkill);
        $cascading = $this->buildCascading($absentUserIds, $usersById);
        $warnings  = $this->buildWarnings($perSkill, $shifts, $perDay);
        $orgAgg    = $this->computeOrgAggregates($perProject);
        $totals    = $this->buildTotals($perProject, $perSkill, $perDay);

        return [
            'totals'                 => $totals,
            'per_user_impact'        => $perUser,
            'per_project_impact'     => $perProject,
            'per_skill_impact'       => array_values($perSkill),
            'per_day_load'           => $perDay,
            'hotspots'               => $hotspots,
            'cascading_risks'        => $cascading,
            'warnings'               => $warnings,
            'comparison_vs_baseline' => $this->buildComparison($orgAgg),
        ];
    }

    /* ─────────────────────── helpers ─────────────────────── */

    private function eachDate(string $start, string $end): array
    {
        $key = $start . '|' . $end;
        if (isset($this->dateCache[$key])) return $this->dateCache[$key];

        $out = [];
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            $out[] = $d->toDateString();
        }
        return $this->dateCache[$key] = $out;
    }

    private function halfDuration(array $a): float
    {
        $days = count($this->eachDate($a['start_date'], $a['end_date']));
        $total = (float) $days;
        if (($a['start_half'] ?? 0) === 1) $total -= 0.5;
        if (($a['end_half'] ?? 1) === 0) $total -= 0.5;
        return max(0.5, $total);
    }

    private function computeUserDays(array $absences): array
    {
        $out = [];
        foreach ($absences as $a) {
            $id = (string) $a['user_id'];
            $out[$id] = ($out[$id] ?? 0) + $this->halfDuration($a);
        }
        return $out;
    }

    private function computeProjectImpact(Project $project, array $absentUserIds, array $absences, array &$skillAgg): array
    {
        // Horizon 0 on both matrices — same availability window as the bus factor / fragility
        // computed below, so the three metrics in one impact row agree on who counts as present.
        $matrixBefore = $this->coverage->getCoverage($project, [], $absentUserIds, 0);
        $matrixAfter  = $this->coverage->getCoverage($project, $absentUserIds, [], 0);

        $uncovered = 0;
        $siloed    = 0;
        $safe      = 0;
        $skillsAtRisk = [];

        foreach ($matrixAfter as $skillId => $rowAfter) {
            $rowBefore = $matrixBefore[$skillId] ?? $rowAfter;
            $cBefore = count($rowBefore['employees']);
            $cAfter  = count($rowAfter['employees']);
            $lost    = array_values(array_diff(
                array_column($rowBefore['employees'], 'user_id'),
                array_column($rowAfter['employees'], 'user_id'),
            ));

            $sev = 'ok';
            if ($rowAfter['status'] === 'uncovered') { $uncovered++; $sev = 'critical'; }
            elseif ($rowAfter['status'] === 'siloed') { $siloed++; $sev = 'warning'; }
            else $safe++;

            if ($cBefore !== $cAfter) {
                $datesAffected = collect($absences)
                    ->filter(fn($a) => in_array((int) $a['user_id'], $lost, true))
                    ->flatMap(fn($a) => $this->eachDate($a['start_date'], $a['end_date']))
                    ->unique()->values()->all();

                $skillsAtRisk[] = [
                    'skill_id'        => $skillId,
                    'name'            => $rowAfter['skill_name'],
                    'required_level'  => $rowAfter['required_level'],
                    'owners_left'     => $cAfter,
                    'severity'        => $sev,
                ];

                $key = $skillId;
                if (!isset($skillAgg[$key])) {
                    $skillAgg[$key] = [
                        'skill_id' => $skillId,
                        'name'     => $rowAfter['skill_name'],
                        'category' => null,
                        'owners_total' => [],
                        'owners_absent' => [],
                        'projects' => [],
                        'dates_uncovered' => [],
                        'is_critical' => false,
                    ];
                }
                foreach ($rowBefore['employees'] as $emp) {
                    $skillAgg[$key]['owners_total'][$emp['user_id']] = true;
                }
                foreach ($lost as $uid) {
                    $skillAgg[$key]['owners_absent'][$uid] = true;
                }
                $skillAgg[$key]['projects'][$project->id] = true;
                if ($sev === 'critical') {
                    foreach ($datesAffected as $d) $skillAgg[$key]['dates_uncovered'][$d] = true;
                    $skillAgg[$key]['is_critical'] = true;
                }
            }
        }

        $totalReqs = $uncovered + $siloed + $safe;
        $coveredBefore = 0;
        foreach ($matrixBefore as $rowBefore) {
            if (($rowBefore['status'] ?? null) !== 'uncovered') $coveredBefore++;
        }
        $reqsBefore = count($matrixBefore);
        $severity  = $uncovered > 0 ? 'critical' : ($siloed > 0 ? 'warning' : 'ok');
        $statusAfter = $uncovered > 0 ? 'blocked' : ($siloed > 0 ? 'at_risk' : 'healthy');

        // Before = clean state. computeRawForProject($project, []) is identical to the persisted
        // metric columns (forProject([]) wrote them), so read the cached column instead of recomputing.
        // After = recompute live with the simulated absent roster.
        $busBefore  = (int) $project->bus_factor;
        $busAfter   = $this->busFactor->computeRawForProject($project, $absentUserIds);
        // Covered = at least one available owner (safe + siloed). Silo fragility is bus
        // factor / fragility's job — counting siloed skills as 0% coverage double-penalizes.
        $covBefore  = $reqsBefore === 0 ? 100 : (int) round(($coveredBefore / $reqsBefore) * 100);
        $covAfter   = $totalReqs === 0 ? 100 : (int) round((($safe + $siloed) / $totalReqs) * 100);
        $riskBefore = (int) round((float) $project->fragility_raw);
        $riskAfter  = (int) round($this->fragility->computeRawForProject($project, $absentUserIds));

        return [
            'project_id'                     => $project->id,
            'name'                           => $project->name,
            'status_after'                   => $statusAfter,
            'bus_factor_before'              => $busBefore,
            'bus_factor_after'               => $busAfter,
            'bus_factor_delta'               => $busAfter - $busBefore,
            'coverage_pct_before'            => $covBefore,
            'coverage_pct_after'             => $covAfter,
            'coverage_delta_pct'             => $covAfter - $covBefore,
            'risk_score_before'              => $riskBefore,
            'risk_score_after'               => $riskAfter,
            'skills_at_risk'                 => $skillsAtRisk,
            'severity'                       => $severity,
        ];
    }

    private function buildSkillImpacts(array $agg): array
    {
        $out = [];
        foreach ($agg as $k => $row) {
            $ownersTotal  = count($row['owners_total']);
            $ownersAbsent = count($row['owners_absent']);
            $ownersLeft   = max(0, $ownersTotal - $ownersAbsent);
            // Before any absence every aggregated skill has ≥1 owner → covered by construction.
            $covBefore    = 100;
            $covAfter     = $ownersTotal === 0 ? 100 : (int) round(($ownersLeft / $ownersTotal) * 100);
            $severity     = $ownersLeft === 0 ? 'critical' : ($ownersLeft <= 2 ? 'warning' : 'ok');
            $out[$k] = [
                'skill_id'             => $row['skill_id'],
                'name'                 => $row['name'],
                'category'             => $row['category'],
                'is_critical_for_org'  => $row['is_critical'],
                'owners_total'         => $ownersTotal,
                'owners_absent'        => $ownersAbsent,
                'owners_left'          => $ownersLeft,
                'coverage_pct_before'  => $covBefore,
                'coverage_pct_after'   => $covAfter,
                'dates_uncovered'      => array_keys($row['dates_uncovered']),
                'projects_impacted'    => array_map('intval', array_keys($row['projects'])),
                'severity'             => $severity,
            ];
        }
        return $out;
    }

    private function buildUserImpacts(array $absences, $usersById, $allUsers, array $perProject, array $userDays, int $workingDays): array
    {
        $out = [];
        foreach ($usersById as $uid => $user) {
            $stringId = (string) $uid;
            $empProjects = collect($perProject)->filter(fn($p) => $user->projects->contains('id', $p['project_id']))->values();
            $severity = $empProjects->contains(fn($p) => $p['severity'] === 'critical')
                ? 'critical'
                : ($empProjects->contains(fn($p) => $p['severity'] === 'warning') ? 'warning' : 'ok');

            $userSkillIds = $user->skills->pluck('id')->all();
            $candidates = $allUsers
                ->filter(fn($u) => $u->id !== $user->id && !isset($usersById[$u->id]))
                ->map(function ($u) use ($userSkillIds, $userDays, $stringId, $workingDays) {
                    $overlap = count(array_intersect($userSkillIds, $u->skills->pluck('id')->all()));
                    $pct     = empty($userSkillIds) ? 0 : (int) round($overlap / count($userSkillIds) * 100);
                    return [
                        'user_id'         => (string) $u->id,
                        'name'            => trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? '')) ?: $u->email,
                        'skill_match_pct' => $pct,
                        'available_days'  => max(0, $workingDays - ($userDays[$stringId] ?? 0)),
                        'cost_signal'     => $pct >= 70 ? 'ok' : ($pct >= 40 ? 'stretch' : 'overloaded'),
                    ];
                })
                ->sortByDesc('skill_match_pct')
                ->take(3)
                ->values()
                ->all();

            $out[$stringId] = [
                'user_id'                  => $stringId,
                'severity'                 => $severity,
                'days_off'                 => $userDays[$stringId] ?? 0,
                'skills_uncovered'         => $empProjects->flatMap(fn($p) => collect($p['skills_at_risk'])->where('severity', 'critical'))
                    ->map(fn($s) => ['skill_id' => $s['skill_id'], 'name' => $s['name'], 'level' => (int) $s['required_level'], 'is_critical' => true, 'owners_left' => $s['owners_left']])
                    ->values()->all(),
                'projects_affected'        => $empProjects->map(fn($p) => [
                    'project_id'  => $p['project_id'],
                    'name'        => $p['name'],
                    'role'        => null,
                    'severity'    => $p['severity'],
                ])->values()->all(),
                'replacement_candidates'   => $candidates,
                'is_critical_employee'     => (int) ($user->criticality_raw ?? 0) >= 70,
                'bus_factor_contribution'  => (int) ($user->bus_factor_in_org_raw ?? 1),
            ];
        }
        return $out;
    }

    private function buildDayLoad(array $absences, array $perSkill, int $totalUsers): array
    {
        $dayMap = [];
        foreach ($absences as $a) {
            $dates = $this->eachDate($a['start_date'], $a['end_date']);
            foreach ($dates as $i => $d) {
                $weight = 1.0;
                if ($i === 0 && ($a['start_half'] ?? 0) === 1) $weight = 0.5;
                if ($i === count($dates) - 1 && ($a['end_half'] ?? 1) === 0) $weight = 0.5;
                if (!isset($dayMap[$d])) $dayMap[$d] = ['absents' => [], 'fte' => 0.0];
                $dayMap[$d]['absents'][(string) $a['user_id']] = true;
                $dayMap[$d]['fte'] += $weight;
            }
        }

        ksort($dayMap);
        $out = [];
        foreach ($dayMap as $date => $info) {
            $absentCount = count($info['absents']);
            $ratio = $totalUsers === 0 ? 0 : $absentCount / $totalUsers;
            $sev = $ratio >= 0.4 ? 'critical' : ($ratio >= 0.25 ? 'warning' : 'ok');
            $dow = (int) Carbon::parse($date)->dayOfWeek;
            $out[] = [
                'date'                     => $date,
                'is_weekend'               => $dow === 0 || $dow === 6,
                'is_holiday'               => false,
                'absent_user_ids'          => array_keys($info['absents']),
                'absent_count'             => $absentCount,
                'absent_fte'               => $info['fte'],
                'coverage_pct'             => (int) round((1 - $ratio) * 100),
                'capacity_pct'             => (int) round((1 - $info['fte'] / max($totalUsers, 1)) * 100),
                'critical_skills_uncovered' => array_values(array_map(
                    fn($s) => $s['skill_id'],
                    array_filter($perSkill, fn($s) => $s['severity'] === 'critical' && in_array($date, $s['dates_uncovered'], true)),
                )),
                'severity'                 => $sev,
            ];
        }
        return $out;
    }

    private function buildHotspots(array $perDay, array $perProject): array
    {
        $hotspots = [];
        $run = null;
        foreach ($perDay as $d) {
            if (in_array($d['severity'], ['warning', 'critical'], true)) {
                if ($run === null) $run = ['start' => $d['date'], 'end' => $d['date'], 'days' => [$d]];
                else { $run['end'] = $d['date']; $run['days'][] = $d; }
            } elseif ($run !== null) {
                $hotspots[] = $this->finalizeHotspot($run, $perProject);
                $run = null;
            }
        }
        if ($run !== null) $hotspots[] = $this->finalizeHotspot($run, $perProject);
        return $hotspots;
    }

    private function finalizeHotspot(array $run, array $perProject): array
    {
        $absentIds = collect($run['days'])->flatMap(fn($d) => $d['absent_user_ids'])->unique()->values()->all();
        $maxSev = collect($run['days'])->contains(fn($d) => $d['severity'] === 'critical') ? 'critical' : 'warning';
        return [
            'date_range'         => [$run['start'], $run['end']],
            'reason'             => count($absentIds) . ' absences overlap',
            'absent_user_ids'    => $absentIds,
            'projects_impacted'  => array_values(array_map(
                fn($p) => $p['project_id'],
                array_filter($perProject, fn($p) => $p['severity'] !== 'ok'),
            )),
            'severity'           => $maxSev,
        ];
    }

    private function buildShifts(array $perSkill): array
    {
        $shifts = [];
        foreach ($perSkill as $s) {
            if ($s['owners_left'] === 1 && $s['owners_total'] > 1) {
                $shifts[] = [
                    'skill_id'             => $s['skill_id'],
                    'skill_name'           => $s['name'],
                    'from_owners'          => $s['owners_total'],
                    'to_owners'            => $s['owners_left'],
                    'new_sole_owner'       => null,
                    'creates_bus_factor_1' => true,
                ];
            }
        }
        return $shifts;
    }

    private function buildCascading(array $absentUserIds, Collection $usersById): array
    {
        $out = [];
        foreach ($absentUserIds as $uid) {
            $u = $usersById[$uid] ?? null;
            if ($u === null) continue;
            if ((int) ($u->criticality_raw ?? 0) < 70) continue;
            $projectName = $u->projects->first()?->name ?? 'a key project';
            $out[] = [
                'type'              => 'DOMINO',
                'trigger_user_id'   => (string) $uid,
                'if_also_absent'    => [],
                'consequence'       => "If a second senior is absent, {$projectName} blocks",
                'probability_hint'  => 'moderate',
            ];
        }
        return $out;
    }

    private function buildWarnings(array $perSkill, array $shifts, array $perDay): array
    {
        $w = [];
        foreach ($perSkill as $s) {
            if ($s['owners_left'] === 0) {
                foreach ($s['dates_uncovered'] as $d) {
                    $w[] = [
                        'code' => 'Critical skill gone',
                        'severity' => 'critical',
                        'skill_id' => $s['skill_id'],
                        'date' => $d,
                        'message' => "No {$s['name']} owner on {$d}",
                        'actionable' => true,
                    ];
                }
            }
        }
        foreach ($shifts as $sh) {
            $w[] = ['code' => 'Bus factor 1 created', 'severity' => 'warning', 'skill_id' => $sh['skill_id'], 'message' => "{$sh['skill_name']} → bus factor 1"];
        }
        foreach ($perDay as $d) {
            if ($d['absent_count'] >= 4) {
                $w[] = ['code' => 'Peak overlap', 'severity' => 'warning', 'date' => $d['date'], 'user_ids' => $d['absent_user_ids'], 'message' => "{$d['absent_count']} absences overlap on {$d['date']}"];
            }
        }
        return $w;
    }

    /**
     * <summary>
     *  Org-level before/after aggregates from real per-project metrics. Unaffected projects use their
     *  cached columns (before == after → zero delta); affected projects use the freshly computed
     *  before/after from per-project impact. Every org delta is therefore driven only by the simulated
     *  projects. Risk = avg fragility (0-100), bus = avg bus factor, cov = avg coverage %, healthy =
     *  count of projects with fragility ≤ 60.
     * </summary>
     *
     * @param array $perProject Affected-project impact rows
     * @return array{risk: array, bus: array, cov: array, healthy: array}
     */
    private function computeOrgAggregates(array $perProject): array
    {
        $affected = [];
        foreach ($perProject as $p) {
            $affected[$p['project_id']] = $p;
        }

        $projects = Project::query()->get(['id', 'fragility_raw', 'bus_factor', 'knowledge_coverage_raw']);
        $n = max(1, $projects->count());

        $riskBefore = $riskAfter = 0.0;
        $busBefore  = $busAfter  = 0.0;
        $covBefore  = $covAfter  = 0.0;
        $healthyBefore = $healthyAfter = 0;

        foreach ($projects as $project) {
            $p = $affected[$project->id] ?? null;

            $rBefore = $p ? (int) $p['risk_score_before']   : (int) $project->fragility_raw;
            $rAfter  = $p ? (int) $p['risk_score_after']    : (int) $project->fragility_raw;
            $bBefore = $p ? (int) $p['bus_factor_before']   : (int) $project->bus_factor;
            $bAfter  = $p ? (int) $p['bus_factor_after']    : (int) $project->bus_factor;
            $cBefore = $p ? (int) $p['coverage_pct_before'] : (int) $project->knowledge_coverage_raw;
            $cAfter  = $p ? (int) $p['coverage_pct_after']  : (int) $project->knowledge_coverage_raw;

            $riskBefore += $rBefore; $riskAfter += $rAfter;
            $busBefore  += $bBefore; $busAfter  += $bAfter;
            $covBefore  += $cBefore; $covAfter  += $cAfter;
            if ($rBefore <= 60) $healthyBefore++;
            if ($rAfter  <= 60) $healthyAfter++;
        }

        return [
            'risk'    => ['before' => (int) round($riskBefore / $n), 'after' => (int) round($riskAfter / $n)],
            'bus'     => ['before' => (int) round($busBefore / $n),  'after' => (int) round($busAfter / $n)],
            'cov'     => ['before' => (int) round($covBefore / $n),  'after' => (int) round($covAfter / $n)],
            'healthy' => ['before' => $healthyBefore, 'after' => $healthyAfter],
        ];
    }

    /**
     * Scenario-scalar headline: worst-case severity + peak overlap. The before/after metric
     * pairs (risk / bus / coverage) live solely in comparison_vs_baseline — not duplicated here.
     */
    private function buildTotals(array $perProject, array $perSkill, array $perDay): array
    {
        $headcountPeak = ['count' => 0, 'date' => null];
        foreach ($perDay as $d) {
            if ($d['absent_count'] > $headcountPeak['count']) {
                $headcountPeak = ['count' => $d['absent_count'], 'date' => $d['date']];
            }
        }

        $projectsAtRisk  = count(array_filter($perProject, fn($p) => $p['severity'] !== 'ok'));
        $projectsBlocked = count(array_filter($perProject, fn($p) => $p['status_after'] === 'blocked'));
        $criticalSkills  = count(array_filter($perSkill, fn($s) => $s['severity'] === 'critical'));
        $severity = $criticalSkills > 0 || $projectsBlocked > 0 ? 'critical' : ($projectsAtRisk > 0 ? 'warning' : 'ok');

        return [
            'absent_headcount_peak'      => $headcountPeak['count'],
            'absent_headcount_peak_date' => $headcountPeak['date'],
            'severity'                   => $severity,
        ];
    }

    private function buildComparison(array $orgAgg): array
    {
        $riskBefore = $orgAgg['risk']['before'];
        $riskAfter  = $orgAgg['risk']['after'];
        return [
            'risk_score'             => ['before' => $riskBefore, 'after' => $riskAfter, 'delta_pct' => $riskBefore === 0 ? 0 : (int) round((($riskAfter - $riskBefore) / $riskBefore) * 100)],
            'bus_factor'             => ['before' => $orgAgg['bus']['before'], 'after' => $orgAgg['bus']['after']],
            'coverage_pct'           => ['before' => $orgAgg['cov']['before'], 'after' => $orgAgg['cov']['after']],
            'projects_healthy_count' => ['before' => $orgAgg['healthy']['before'], 'after' => $orgAgg['healthy']['after']],
        ];
    }

    private function emptyResponse(string $month): array
    {
        $orgAgg = $this->computeOrgAggregates([]);
        $risk = $orgAgg['risk']['before'];
        $bus  = $orgAgg['bus']['before'];
        $cov  = $orgAgg['cov']['before'];
        return [
            'totals' => [
                'absent_headcount_peak' => 0,
                'absent_headcount_peak_date' => null,
                'severity' => 'ok',
            ],
            'per_user_impact' => (object) [],
            'per_project_impact' => [],
            'per_skill_impact' => [],
            'per_day_load' => [],
            'hotspots' => [],
            'cascading_risks' => [],
            'warnings' => [],
            'comparison_vs_baseline' => [
                'risk_score' => ['before' => $risk, 'after' => $risk, 'delta_pct' => 0],
                'bus_factor' => ['before' => $bus, 'after' => $bus],
                'coverage_pct' => ['before' => $cov, 'after' => $cov],
                'projects_healthy_count' => ['before' => $orgAgg['healthy']['before'], 'after' => $orgAgg['healthy']['before']],
            ],
        ];
    }
}
