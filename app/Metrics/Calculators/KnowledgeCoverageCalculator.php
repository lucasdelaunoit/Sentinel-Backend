<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Services\SkillCoverageService;

/**
 * Knowledge coverage metric — KPI is the % of required skills marked 'safe'.
 * Detail breaks coverage down per skill category with siloed/uncovered lists.
 */
class KnowledgeCoverageCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
    ) {}

    /**
     * <summary>
     *  Headline KPI — org-wide % of required skills currently 'safe'.
     * </summary>
     *
     * @return array{raw: int, insight: string, under_covered: int}
     */
    public function kpi(): array
    {
        $projects = Project::active()
            ->with(['skillRequirements', 'users.skills', 'users.absences'])
            ->get();

        $total = 0;
        $safe = 0;

        foreach ($projects as $project) {
            foreach ($this->coverageService->getCoverage($project) as $skill) {
                $total++;
                if ($skill['status'] === 'safe') {
                    $safe++;
                }
            }
        }

        $underCovered = $total - $safe;
        $pct = $total > 0 ? (int) round(($safe / $total) * 100) : 100;

        return [
            'raw' => $pct,
            'insight' => $underCovered > 0
                ? "{$underCovered} skill" . ($underCovered > 1 ? 's' : '') . ' under-covered'
                : 'All skills covered',
            'under_covered' => $underCovered,
        ];
    }

    /**
     * <summary>
     *  Drilldown — coverage breakdown grouped by skill category, sorted by lowest coverage_pct first.
     *  Each category exposes siloed_skills + uncovered_skills lists.
     * </summary>
     *
     * @return array{categories: array<int, array>, most_fragile: ?string}
     */
    public function detail(): array
    {
        $projects = Project::active()
            ->with(['skillRequirements.category', 'users.skills', 'users.absences'])
            ->get();

        $byCategory = [];

        foreach ($projects as $project) {
            $matrix = $this->coverageService->getCoverage($project);
            $catLookup = $project->skillRequirements->keyBy('id');

            foreach ($matrix as $skillId => $skill) {
                $cat = $catLookup[$skillId] ?? null;
                $catId = $cat?->category?->id ?? 0;
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
}
