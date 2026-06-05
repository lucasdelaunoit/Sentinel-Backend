<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * <summary>
 *  Rich planning + absence-simulation engine. Produces both the month payload
 *  (used by the Gantt view) and the rich simulate payload (totals, per-user /
 *  per-project / per-skill impact, day-load, hotspots, cascading risks,
 *  warnings, recommendations, comparison vs baseline). Reuses SkillCoverageService
 *  for the per-project coverage matrix so business rules stay consistent.
 * </summary>
 */
class PlanningService
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
        private readonly OrganizationSettingService $orgSettings,
    ) {}

    /* ─────────────────────── Month payload ─────────────────────── */

    public function getMonth(string $month): array
    {
        [$year, $monthNum] = $this->parseMonth($month);
        $monthStart = Carbon::create($year, $monthNum, 1);
        $monthEnd   = (clone $monthStart)->endOfMonth();

        $users = User::query()
            ->with(['department', 'skills', 'projects', 'absences' => function ($q) use ($monthStart, $monthEnd) {
                $q->where('start_date', '<=', $monthEnd)->where('end_date', '>=', $monthStart);
            }])
            ->orderBy('lastname')
            ->get();

        $todayStr = Carbon::today()->toDateString();
        $isCurrentMonth = Carbon::now()->year === $year && Carbon::now()->month === $monthNum;

        return [
            'month'         => $month,
            'users'         => $users->map(fn(User $u) => $this->formatUser($u))->all(),
            'capacity_today' => $isCurrentMonth ? $this->capacityToday($todayStr) : null,
        ];
    }

    private function formatUser(User $u): array
    {
        return [
            'id'         => (string) $u->id,
            'firstname'  => $u->firstname ?? '',
            'lastname'   => $u->lastname ?? '',
            'initials'   => $this->initials($u),
            'title'      => $u->title ?? '',
            'department' => $u->department ? ['id' => $u->department->id, 'name' => $u->department->name] : null,
            'color'      => 'bg-slate-500',
            'skills'     => $u->skills->map(fn($s) => [
                'id'    => $s->id,
                'name'  => $s->name,
                'level' => (int) ($s->pivot->level ?? 0),
            ])->values()->all(),
            'projects'   => $u->projects->map(fn($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'absences'   => $u->absences->map(fn(Absence $a) => [
                'id'         => $a->id,
                'type'       => $a->type?->value,
                'start_date' => $a->start_date?->toDateString(),
                'start_half' => 0,
                'end_date'   => $a->end_date?->toDateString(),
                'end_half'   => 1,
                'reason'     => $a->reason,
            ])->values()->all(),
        ];
    }

    private function capacityToday(string $today): array
    {
        $total   = User::query()->count();
        $onLeave = User::query()->whereHas('absences', fn($q) => $q
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today))->count();
        return ['available' => $total - $onLeave, 'on_leave' => $onLeave, 'total' => $total];
    }

    /* ─────────────────────── Apply scenario ─────────────────────── */

    public function apply(array $absences): array
    {
        $created = DB::transaction(function () use ($absences) {
            $ids = [];
            foreach ($absences as $a) {
                $abs = Absence::create([
                    'user_id'    => (int) $a['user_id'],
                    'start_date' => $a['start_date'],
                    'end_date'   => $a['end_date'],
                    'type'       => $a['type'] ?? 'planned',
                    'reason'     => $a['reason'] ?? null,
                ]);
                $ids[] = $abs->id;
            }
            return $ids;
        });

        return ['applied' => count($created), 'created_ids' => $created];
    }

    /* ─────────────────────── Simulate ─────────────────────── */

    public function simulate(array $absences, ?string $month = null): array
    {
        $start = microtime(true);

        $month ??= !empty($absences) ? substr($absences[0]['start_date'], 0, 7) : Carbon::now()->format('Y-m');

        if (empty($absences)) {
            return $this->emptyResponse($month, $start);
        }

        $absentUserIds = collect($absences)->pluck('user_id')->map(fn($v) => (int) $v)->unique()->values()->all();
        $usersById     = User::query()
            ->whereIn('id', $absentUserIds)
            ->with(['skills', 'projects.skillRequirements', 'projects.users.skills', 'projects.users.absences'])
            ->get()
            ->keyBy('id');

        $allUsers = User::query()->with(['skills'])->get();

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
        $perUser  = $this->buildUserImpacts($absences, $usersById, $allUsers, $perProject, $userDays);
        $perDay   = $this->buildDayLoad($absences, $perSkill, $allUsers->count());
        $hotspots = $this->buildHotspots($perDay, $perProject);
        $shifts   = $this->buildShifts($perSkill);
        $cascading = $this->buildCascading($absentUserIds, $usersById);
        $warnings  = $this->buildWarnings($perSkill, $shifts, $perDay);
        $recs      = $this->buildRecommendations($perUser, $perSkill, $hotspots, $usersById);
        $totals    = $this->buildTotals($perProject, $perSkill, $perDay, $shifts);

        return [
            'totals'                     => $totals,
            'per_user_impact'            => $perUser,
            'per_project_impact'         => $perProject,
            'per_skill_impact'           => array_values($perSkill),
            'per_day_load'               => $perDay,
            'hotspots'                   => $hotspots,
            'skill_concentration_shifts' => $shifts,
            'cascading_risks'            => $cascading,
            'warnings'                   => $warnings,
            'recommendations'            => $recs,
            'comparison_vs_baseline'     => $this->buildComparison($totals),
            'meta' => [
                'computed_at'        => Carbon::now()->toIso8601String(),
                'computation_ms'     => (int) round((microtime(true) - $start) * 1000),
                'absences_evaluated' => count($absences),
                'month'              => $month,
            ],
            'overall_level' => $totals['severity'] === 'critical' ? 'critical' : ($totals['severity'] === 'high' ? 'warning' : 'safe'),
            'projects'      => $perProject,
        ];
    }

    /* ─────────────────────── helpers ─────────────────────── */

    private function parseMonth(string $month): array
    {
        [$y, $m] = explode('-', $month);
        return [(int) $y, (int) $m];
    }

    private function initials(User $u): string
    {
        $f = strtoupper(substr($u->firstname ?? '', 0, 1));
        $l = strtoupper(substr($u->lastname ?? '', 0, 1));
        return $f . $l ?: 'U';
    }

    private function eachDate(string $start, string $end): array
    {
        $out = [];
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            $out[] = $d->toDateString();
        }
        return $out;
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
        $matrixBefore = $this->coverage->getCoverage($project, [], $absentUserIds);
        $matrixAfter  = $this->coverage->getCoverage($project, $absentUserIds);

        $uncovered = 0;
        $siloed    = 0;
        $safe      = 0;
        $skillsAtRisk = [];
        $spofs        = [];

        foreach ($matrixAfter as $skillId => $rowAfter) {
            $rowBefore = $matrixBefore[$skillId] ?? $rowAfter;
            $cBefore = count($rowBefore['employees']);
            $cAfter  = count($rowAfter['employees']);
            $lost    = array_values(array_diff(
                array_column($rowBefore['employees'], 'user_id'),
                array_column($rowAfter['employees'], 'user_id'),
            ));

            $sev = 'safe';
            if ($rowAfter['status'] === 'uncovered') { $uncovered++; $sev = 'critical'; }
            elseif ($rowAfter['status'] === 'siloed') {
                $siloed++; $sev = 'medium';
                if ($cBefore > 1 && $cAfter === 1) {
                    $spofs[] = ['skill_id' => $skillId, 'skill_name' => $rowAfter['skill_name'], 'owner_left' => (string) $rowAfter['employees'][0]['user_id']];
                }
            } else $safe++;

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
                    'owners_lost'     => array_map('strval', $lost),
                    'severity'        => $sev,
                    'dates_affected'  => $datesAffected,
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
        $level     = $uncovered > 0 ? 'critical' : ($siloed > 0 ? 'warning' : 'safe');
        $statusAfter = $uncovered > 0 ? 'blocked' : ($siloed > 0 ? 'at_risk' : 'healthy');
        $statusBefore = 'healthy';

        $busBefore = max(1, (int) ($project->bus_factor ?? 1));
        $teamAbsent = collect($absences)->filter(fn($a) => $project->users->contains('id', (int) $a['user_id']))->count();
        $busAfter   = max(1, $busBefore - min($teamAbsent, $busBefore - 1));
        $covBefore  = $totalReqs === 0 ? 100 : 100;
        $covAfter   = $totalReqs === 0 ? 100 : (int) round(($safe / $totalReqs) * 100);
        $riskBefore = (int) ($project->fragility_raw ?? 0);
        $riskAfter  = min(100, (int) round($riskBefore + $uncovered * 18 + $siloed * 8));

        return [
            'project_id'                     => $project->id,
            'name'                           => $project->name,
            'status_before'                  => $statusBefore,
            'status_after'                   => $statusAfter,
            'bus_factor_before'              => $busBefore,
            'bus_factor_after'               => $busAfter,
            'bus_factor_delta'               => $busAfter - $busBefore,
            'coverage_pct_before'            => $covBefore,
            'coverage_pct_after'             => $covAfter,
            'coverage_delta_pct'             => $covAfter - $covBefore,
            'risk_score_before'              => $riskBefore,
            'risk_score_after'               => $riskAfter,
            'risk_delta'                     => $riskAfter - $riskBefore,
            'skills_at_risk'                 => $skillsAtRisk,
            'single_points_of_failure_created' => $spofs,
            'milestones_at_risk'             => $statusAfter === 'blocked' && $project->deadline
                ? [['id' => "{$project->id}-m1", 'name' => 'Deadline', 'date' => $project->deadline->toDateString(), 'confidence_delta_pct' => -25]]
                : [],
            'effective_team_size_before'     => $project->users->count(),
            'effective_team_size_after'      => $project->users->count() - $teamAbsent,
            'recommendation'                 => $uncovered > 0 && !empty($skillsAtRisk)
                ? "Cover {$skillsAtRisk[0]['name']} — reassign or upskill"
                : ($siloed > 0 ? "Cross-train on " . ($skillsAtRisk[0]['name'] ?? 'critical skill') : null),
            'level'                          => $level,
        ];
    }

    private function buildSkillImpacts(array $agg): array
    {
        $out = [];
        foreach ($agg as $k => $row) {
            $ownersTotal  = count($row['owners_total']);
            $ownersAbsent = count($row['owners_absent']);
            $ownersLeft   = max(0, $ownersTotal - $ownersAbsent);
            $covBefore    = $ownersTotal === 0 ? 100 : 100;
            $covAfter     = $ownersTotal === 0 ? 100 : (int) round(($ownersLeft / $ownersTotal) * 100);
            $severity     = $ownersLeft === 0 ? 'critical' : ($ownersLeft === 1 ? 'high' : ($ownersLeft <= 2 ? 'medium' : 'low'));
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
                'redundancy_before'    => max(0, $ownersTotal - 1),
                'redundancy_after'     => max(0, $ownersLeft - 1),
                'dates_uncovered'      => array_keys($row['dates_uncovered']),
                'projects_impacted'    => array_map('intval', array_keys($row['projects'])),
                'severity'             => $severity,
            ];
        }
        return $out;
    }

    private function buildUserImpacts(array $absences, $usersById, $allUsers, array $perProject, array $userDays): array
    {
        $out = [];
        foreach ($usersById as $uid => $user) {
            $stringId = (string) $uid;
            $empProjects = collect($perProject)->filter(fn($p) => $user->projects->contains('id', $p['project_id']))->values();
            $level = $empProjects->contains(fn($p) => $p['level'] === 'critical')
                ? 'critical'
                : ($empProjects->contains(fn($p) => $p['level'] === 'warning') ? 'warning' : 'safe');

            $userSkillIds = $user->skills->pluck('id')->all();
            $candidates = $allUsers
                ->filter(fn($u) => $u->id !== $user->id && !isset($usersById[$u->id]))
                ->map(function ($u) use ($userSkillIds, $userDays, $stringId) {
                    $overlap = count(array_intersect($userSkillIds, $u->skills->pluck('id')->all()));
                    $pct     = empty($userSkillIds) ? 0 : (int) round($overlap / count($userSkillIds) * 100);
                    return [
                        'user_id'         => (string) $u->id,
                        'name'            => trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? '')) ?: $u->email,
                        'skill_match_pct' => $pct,
                        'available_days'  => max(0, 20 - ($userDays[$stringId] ?? 0)),
                        'cost_signal'     => $pct >= 70 ? 'ok' : ($pct >= 40 ? 'stretch' : 'overloaded'),
                    ];
                })
                ->sortByDesc('skill_match_pct')
                ->take(3)
                ->values()
                ->all();

            $myDates = collect($absences)
                ->filter(fn($a) => (string) $a['user_id'] === $stringId)
                ->flatMap(fn($a) => $this->eachDate($a['start_date'], $a['end_date']))
                ->unique()->values()->all();
            $overlapHints = collect($absences)
                ->filter(fn($a) => (string) $a['user_id'] !== $stringId)
                ->groupBy('user_id')
                ->map(function ($group, $otherId) use ($myDates) {
                    $dates = collect($group)->flatMap(fn($a) => $this->eachDate($a['start_date'], $a['end_date']))
                        ->intersect($myDates)->unique()->values()->all();
                    return $dates ? ['user_id' => (string) $otherId, 'dates' => $dates] : null;
                })
                ->filter()->values()->all();

            $out[$stringId] = [
                'user_id'                  => $stringId,
                'level'                    => $level,
                'days_off'                 => $userDays[$stringId] ?? 0,
                'working_days_in_month'    => 20,
                'absence_ratio_pct'        => (int) round((($userDays[$stringId] ?? 0) / 20) * 100),
                'skills_uncovered'         => $empProjects->flatMap(fn($p) => collect($p['skills_at_risk'])->where('severity', 'critical'))
                    ->map(fn($s) => ['skill_id' => $s['skill_id'], 'name' => $s['name'], 'level' => 4, 'is_critical' => true, 'owners_left' => $s['owners_left']])
                    ->values()->all(),
                'projects_affected'        => $empProjects->map(fn($p) => [
                    'project_id'  => $p['project_id'],
                    'name'        => $p['name'],
                    'role'        => null,
                    'criticality' => $p['level'] === 'critical' ? 'critical' : ($p['level'] === 'warning' ? 'medium' : 'safe'),
                ])->values()->all(),
                'replacement_candidates'   => $candidates,
                'overlap_with_other_sims'  => $overlapHints,
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
            $sev = $ratio >= 0.4 ? 'critical' : ($ratio >= 0.25 ? 'high' : ($ratio >= 0.15 ? 'medium' : ($ratio > 0 ? 'low' : 'safe')));
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
            if (in_array($d['severity'], ['high', 'critical'], true)) {
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
        $maxSev = collect($run['days'])->contains(fn($d) => $d['severity'] === 'critical') ? 'critical' : 'high';
        return [
            'date_range'         => [$run['start'], $run['end']],
            'reason'             => count($absentIds) . ' absences overlap',
            'absent_user_ids'    => $absentIds,
            'projects_impacted'  => array_values(array_map(
                fn($p) => $p['project_id'],
                array_filter($perProject, fn($p) => $p['level'] !== 'safe'),
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
                        'code'       => 'CRITICAL_SKILL_GONE',
                        'severity'   => 'critical',
                        'skill_id'   => $s['skill_id'],
                        'date'       => $d,
                        'message'    => "No {$s['name']} owner on {$d}",
                        'actionable' => true,
                    ];
                }
            }
        }
        foreach ($shifts as $sh) {
            $w[] = ['code' => 'BUS_FACTOR_1_CREATED', 'severity' => 'high', 'skill_id' => $sh['skill_id'], 'message' => "{$sh['skill_name']} → bus factor 1"];
        }
        foreach ($perDay as $d) {
            if ($d['absent_count'] >= 4) {
                $w[] = ['code' => 'PEAK_OVERLAP', 'severity' => 'high', 'date' => $d['date'], 'user_ids' => $d['absent_user_ids'], 'message' => "{$d['absent_count']} absences overlap on {$d['date']}"];
            }
        }
        return $w;
    }

    private function buildRecommendations(array $perUser, array $perSkill, array $hotspots, Collection $usersById): array
    {
        $recs = [];
        $i = 1;
        foreach ($perUser as $u) {
            $top = $u['replacement_candidates'][0] ?? null;
            if ($u['level'] === 'critical' && $top && $top['skill_match_pct'] >= 70) {
                $name = $usersById[(int) $u['user_id']]?->firstname ?? "user {$u['user_id']}";
                $recs[] = [
                    'id'              => 'r' . $i,
                    'type'            => 'REASSIGN',
                    'priority'        => $i++,
                    'title'           => "Cover for {$name}",
                    'detail'          => "Assign {$top['name']} ({$top['skill_match_pct']}% skill match) during absence",
                    'impact_preview'  => ['risk_score_delta' => -12, 'coverage_delta_pct' => 8],
                ];
            }
        }
        foreach ($perSkill as $s) {
            if ($s['owners_left'] <= 1 && $s['is_critical_for_org']) {
                $recs[] = [
                    'id'        => 'r' . $i,
                    'type'      => 'UPSKILL',
                    'priority'  => $i++,
                    'title'     => "Cross-train on {$s['name']}",
                    'detail'    => "Only {$s['owners_left']} owner" . ($s['owners_left'] === 1 ? '' : 's') . " left — train one more engineer",
                ];
            }
        }
        foreach ($hotspots as $h) {
            if ($h['severity'] === 'critical') {
                $recs[] = [
                    'id'              => 'r' . $i,
                    'type'            => 'RESCHEDULE',
                    'priority'        => $i++,
                    'title'           => "Spread absences around {$h['date_range'][0]}",
                    'detail'          => count($h['absent_user_ids']) . " overlapping — shift one absence by 2 days",
                    'impact_preview'  => ['absent_headcount_peak' => max(1, count($h['absent_user_ids']) - 1)],
                ];
            }
        }
        return $recs;
    }

    private function buildTotals(array $perProject, array $perSkill, array $perDay, array $shifts): array
    {
        $settings = $this->orgSettings->getOrganizationSetting();
        $baseRisk = 42;
        $baseBus  = 3;
        $baseCov  = 78;

        $headcountPeak = ['count' => 0, 'date' => null];
        $fteDays = 0.0;
        foreach ($perDay as $d) {
            $fteDays += $d['absent_fte'];
            if ($d['absent_count'] > $headcountPeak['count']) {
                $headcountPeak = ['count' => $d['absent_count'], 'date' => $d['date']];
            }
        }

        $projectsAtRisk = count(array_filter($perProject, fn($p) => $p['level'] !== 'safe'));
        $projectsBlocked = count(array_filter($perProject, fn($p) => $p['status_after'] === 'blocked'));
        $criticalSkills = count(array_filter($perSkill, fn($s) => $s['severity'] === 'critical'));

        $riskAfter = min(100, $baseRisk + $projectsAtRisk * 5 + $projectsBlocked * 10 + $criticalSkills * 6);
        $covAfter  = max(0, $baseCov - $projectsAtRisk * 3 - $criticalSkills * 4);
        $busAfter  = max(1, $baseBus - count($shifts));

        $severity = $criticalSkills > 0 || $projectsBlocked > 0 ? 'critical' : ($projectsAtRisk > 0 ? 'high' : 'low');
        $totalUsers = max(1, User::query()->count());

        return [
            'risk_score'                     => $riskAfter,
            'risk_score_delta'               => $riskAfter - $baseRisk,
            'bus_factor'                     => $busAfter,
            'bus_factor_delta'               => $busAfter - $baseBus,
            'coverage_pct'                   => $covAfter,
            'coverage_delta_pct'             => $covAfter - $baseCov,
            'absent_fte_days'                => round($fteDays, 1),
            'absent_headcount_peak'          => $headcountPeak['count'],
            'absent_headcount_peak_date'     => $headcountPeak['date'],
            'org_capacity_loss_pct'          => (int) round(($fteDays / ($totalUsers * 20)) * 100),
            'projects_at_risk_count'         => $projectsAtRisk,
            'projects_blocked_count'         => $projectsBlocked,
            'critical_skills_uncovered_count' => $criticalSkills,
            'severity'                       => $severity,
        ];
    }

    private function buildComparison(array $totals): array
    {
        $baseRisk = 42; $baseBus = 3; $baseCov = 78;
        return [
            'risk_score'             => ['before' => $baseRisk, 'after' => $totals['risk_score'], 'delta_pct' => $baseRisk === 0 ? 0 : (int) round((($totals['risk_score'] - $baseRisk) / $baseRisk) * 100)],
            'bus_factor'             => ['before' => $baseBus, 'after' => $totals['bus_factor']],
            'coverage_pct'           => ['before' => $baseCov, 'after' => $totals['coverage_pct']],
            'projects_healthy_count' => ['before' => Project::query()->count(), 'after' => Project::query()->count() - $totals['projects_blocked_count']],
        ];
    }

    private function emptyResponse(string $month, float $startMicro): array
    {
        $baseRisk = 42; $baseBus = 3; $baseCov = 78;
        return [
            'totals' => [
                'risk_score' => $baseRisk, 'risk_score_delta' => 0,
                'bus_factor' => $baseBus, 'bus_factor_delta' => 0,
                'coverage_pct' => $baseCov, 'coverage_delta_pct' => 0,
                'absent_fte_days' => 0, 'absent_headcount_peak' => 0, 'absent_headcount_peak_date' => null,
                'org_capacity_loss_pct' => 0,
                'projects_at_risk_count' => 0, 'projects_blocked_count' => 0,
                'critical_skills_uncovered_count' => 0,
                'severity' => 'safe',
            ],
            'per_user_impact' => (object) [],
            'per_project_impact' => [],
            'per_skill_impact' => [],
            'per_day_load' => [],
            'hotspots' => [],
            'skill_concentration_shifts' => [],
            'cascading_risks' => [],
            'warnings' => [],
            'recommendations' => [],
            'comparison_vs_baseline' => [
                'risk_score' => ['before' => $baseRisk, 'after' => $baseRisk, 'delta_pct' => 0],
                'bus_factor' => ['before' => $baseBus, 'after' => $baseBus],
                'coverage_pct' => ['before' => $baseCov, 'after' => $baseCov],
                'projects_healthy_count' => ['before' => 0, 'after' => 0],
            ],
            'meta' => [
                'computed_at' => Carbon::now()->toIso8601String(),
                'computation_ms' => (int) round((microtime(true) - $startMicro) * 1000),
                'absences_evaluated' => 0,
                'month' => $month,
            ],
            'overall_level' => 'safe',
            'projects' => [],
        ];
    }
}
