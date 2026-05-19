<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Services\SkillCoverageService;

/**
 * Computes org-wide knowledge coverage — % of required skills marked 'safe'
 * across all active projects. Iterates SkillCoverageService once per project.
 */
class KnowledgeCoverageCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverageService,
    ) {}

    /**
     * @return array{raw: int, insight: ?string, under_covered: int}
     */
    public function compute(): array
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
}
