<?php

namespace App\Metrics\Calculators;

use App\Metrics\FragilityScale;
use App\Models\Project;
use App\Services\SkillCoverageService;

/**
 * Fragility metric — both the headline KPI (worst active project's score)
 * and the at-risk drilldown payload (critical + unstable buckets with
 * missing / siloed skills per project).
 */
class FragilityCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
    ) {}

    /**
     * <summary>
     *  Headline KPI — worst active project's fragility score with a tier-count insight.
     * </summary>
     *
     * @return array{raw: int, insight: string}
     */
    public function kpi(): array
    {
        $scores = Project::active()->pluck('fragility_raw');

        $worst = (int) ($scores->max() ?? 0);
        $fragile = $scores->filter(fn($v) => $v > 60)->count();
        $stretched = $scores->filter(fn($v) => $v > 40 && $v <= 60)->count();

        $parts = [];
        if ($fragile > 0) {
            $parts[] = "{$fragile} fragile";
        }
        if ($stretched > 0) {
            $parts[] = "{$stretched} stretched";
        }

        return [
            'raw' => $worst,
            'insight' => empty($parts) ? 'All projects healthy' : implode(' · ', $parts),
        ];
    }

    /**
     * <summary>
     *  Drilldown — projects with fragility > 40, split into critical (>60) and unstable (40-60),
     *  each enriched with missing + siloed skills from the live coverage matrix.
     * </summary>
     *
     * @return array{critical: array<int, array>, unstable: array<int, array>}
     */
    public function detail(): array
    {
        $projects = Project::active()
            ->where('fragility_raw', '>', 40)
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->orderByDesc('fragility_raw')
            ->get();

        $mapProject = function (Project $p) {
            $matrix = $this->coverageService->getCoverage($p);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'fragility_raw' => $p->fragility_raw,
                'fragility' => FragilityScale::fromRaw($p->fragility_raw)->value,
                'bus_factor' => $p->bus_factor,
                'missing_skills' => collect($matrix)
                    ->where('status', 'uncovered')
                    ->map(fn($s) => ['skill_id' => $s['skill_id'], 'skill_name' => $s['skill_name']])
                    ->values()->all(),
                'siloed_skills' => collect($matrix)
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
            'critical' => $projects->filter(fn($p) => $p->fragility_raw > 60)->map($mapProject)->values()->all(),
            'unstable' => $projects->filter(fn($p) => $p->fragility_raw > 40 && $p->fragility_raw <= 60)->map($mapProject)->values()->all(),
        ];
    }
}
