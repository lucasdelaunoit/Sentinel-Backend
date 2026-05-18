<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SkillCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RiskCalculationService
{
    public function __construct(
        private readonly SkillCoverageService        $coverage,
        private readonly OrganizationSettingService  $orgSettings,
    ) {}

    public function computeBusFactor(Project $project): int
    {
        $matrix = $this->coverage->getCoverage($project);

        $covered = collect($matrix)->filter(fn($s) => count($s['employees']) > 0);

        if ($covered->isEmpty()) return 0;

        return (int) $covered->min(fn($s) => count($s['employees']));
    }

    /**
     * <summary>
     *  Compute structural fragility 0-100 (higher = more fragile).
     *  Weighted blend of bus-factor risk, uncovered skills, silos, upcoming absence impact.
     *  Multiplied by fragility_tolerance.
     * </summary>
     *
     * @param Project $project Target project
     * @return float Fragility raw score (0-100)
     */
    public function computeFragilityRaw(Project $project): float
    {
        $matrix = $this->coverage->getCoverage($project);
        $total  = count($matrix);

        if ($total === 0) return 0.0;

        $settings = $this->orgSettings->getOrganizationSetting();

        $uncovered  = collect($matrix)->where('status', 'uncovered')->count();
        $siloed     = collect($matrix)->where('status', 'siloed')->count();
        $busFactor  = $this->computeBusFactor($project);

        $busRisk       = $busFactor >= 5 ? 0 : max(0, 100 - $busFactor * 20);
        $uncoveredRisk = ($uncovered / $total) * 100;
        $siloRisk      = ($siloed / $total) * 100;
        $absenceRisk   = $this->computeAbsenceImpact($project, $matrix);

        $weights = [
            $settings->fragility_weight_bus_factor,
            $settings->fragility_weight_uncovered_skills,
            $settings->fragility_weight_silos,
            $settings->fragility_weight_absence_impact,
        ];
        $sum = max(1, array_sum($weights));

        $weighted = (
            $busRisk       * $weights[0] +
            $uncoveredRisk * $weights[1] +
            $siloRisk      * $weights[2] +
            $absenceRisk   * $weights[3]
        ) / $sum;

        $tolerance = match ($settings->fragility_tolerance) {
            'conservative' => 1.2,
            'aggressive'   => 0.8,
            default        => 1.0,
        };

        return round(min(100, $weighted * $tolerance), 2);
    }

    /**
     * <summary>
     *  Compute forward-looking trajectory 0-100 (higher = better outlook).
     *  Blends inverted fragility with progress using trajectory_fragility_weight as the fragility share.
     * </summary>
     *
     * @param Project $project Target project
     * @return float Trajectory raw score (0-100)
     */
    public function computeTrajectoryRaw(Project $project): float
    {
        $fragility = $this->computeFragilityRaw($project);
        $progress  = (float) ($project->progress ?? 0);

        $fragShare = $this->orgSettings->getOrganizationSetting()->trajectory_fragility_weight / 100.0;
        $progShare = 1.0 - $fragShare;

        return round((100 - $fragility) * $fragShare + $progress * $progShare, 2);
    }

    /**
     * <summary>
     *  Map a fragility raw score (0-100) to its tier key. Higher = worse.
     * </summary>
     *
     * @param float|int $raw Raw fragility score
     * @return string Tier key: solid|stable|stretched|fragile|critical
     */
    public static function fragilityTier(float|int $raw): string
    {
        return match (true) {
            $raw <= 20 => 'solid',
            $raw <= 40 => 'stable',
            $raw <= 60 => 'stretched',
            $raw <= 80 => 'fragile',
            default    => 'critical',
        };
    }

    /**
     * <summary>
     *  Map a trajectory raw score (0-100) to its tier key. Higher = better.
     * </summary>
     *
     * @param float|int $raw Raw trajectory score
     * @return string Tier key: off_course|drifting|wobbling|on_track|cruising
     */
    public static function trajectoryTier(float|int $raw): string
    {
        return match (true) {
            $raw <= 20 => 'off_course',
            $raw <= 40 => 'drifting',
            $raw <= 60 => 'wobbling',
            $raw <= 80 => 'on_track',
            default    => 'cruising',
        };
    }

    public function computeKCI(SkillCategory $category): float
    {
        $category->loadMissing('skills.users');

        $minLevel = $this->orgSettings->getOrganizationSetting()->kci_min_level;

        $allIds       = $category->skills->flatMap(fn($s) => $s->users->pluck('id'))->unique();
        $qualifiedIds = $category->skills->flatMap(fn($s) =>
            $s->users->filter(fn($u) => $u->pivot->level >= $minLevel)->pluck('id')
        )->unique();

        if ($allIds->isEmpty()) return 0.0;

        return round(($qualifiedIds->count() / $allIds->count()) * 100, 2);
    }

    public function computeUserCriticality(User $user): array
    {
        $user->loadMissing(['skills', 'projects.skillRequirements', 'projects.users.skills']);

        $criticalBf = $this->orgSettings->getOrganizationSetting()->critical_bus_factor_threshold;

        $skillIds = $user->skills->pluck('id');
        $uniqueSkillCount = DB::table('user_skills')
            ->select('skill_id')
            ->whereIn('skill_id', $skillIds)
            ->groupBy('skill_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('skill_id')
            ->count();

        $siloCount      = 0;
        $busFactorCount = 0;

        foreach ($user->projects as $project) {
            $matrix = $this->coverage->getCoverage($project);

            foreach ($matrix as $skillCoverage) {
                if ($skillCoverage['status'] === 'siloed') {
                    $inSilo = collect($skillCoverage['employees'])->contains('user_id', $user->id);
                    if ($inSilo) {
                        $siloCount++;
                        break;
                    }
                }
            }

            $busFactor = $this->computeBusFactor($project);
            if ($busFactor <= $criticalBf) {
                $busFactorCount++;
            }
        }

        $score = round(
            ($uniqueSkillCount * 30) +
            ($siloCount * 20) +
            ($busFactorCount * 10),
            2
        );

        return [
            'score'               => min(100, $score),
            'unique_skills'       => $uniqueSkillCount,
            'silo_count'          => $siloCount,
            'bus_factor_projects' => $busFactorCount,
        ];
    }

    private function computeAbsenceImpact(Project $project, array $baseline): float
    {
        $horizon = $this->orgSettings->getOrganizationSetting()->absence_horizon_days;
        $today   = Carbon::today();
        $until   = $today->copy()->addDays($horizon);

        $project->loadMissing('users.absences');

        $absentIds = $project->users
            ->filter(fn($u) => $u->absences->contains(
                fn($a) => Carbon::parse($a->start_date)->lte($until)
                    && Carbon::parse($a->end_date)->gte($today)
            ))
            ->pluck('id')
            ->all();

        if (empty($absentIds)) return 0.0;

        $withAbsence = $this->coverage->getCoverageAfterAbsence($project, $absentIds);
        $total       = count($withAbsence);

        if ($total === 0) return 0.0;

        $newlyUncovered = collect($withAbsence)
            ->filter(fn($s, $id) =>
                $s['status'] === 'uncovered' &&
                isset($baseline[$id]) &&
                $baseline[$id]['status'] !== 'uncovered'
            )
            ->count();

        return ($newlyUncovered / $total) * 100;
    }
}
