<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Services\SkillCoverageService;

/**
 * Raw knowledge-coverage score (0-100) for a project.
 *
 * Score = % of required skills with status === 'safe'. Siloed and uncovered
 * skills both count against coverage.
 *
 * Accepts optional $absentUserIds so the same code path serves the live state
 * AND simulation runs (virtual absence roster).
 */
class KnowledgeCoverageCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
    ) {}

    /**
     * <summary>
     *  Compute the raw knowledge-coverage score (float 0-100) for a project.
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int> $absentUserIds Virtual absence roster (simulation). Empty for live state.
     * @return float
     */
    public function calculate(Project $project, array $absentUserIds = []): float
    {
        $matrix = $this->coverage->getCoverage($project, $absentUserIds);
        $total = count($matrix);

        if ($total === 0) return 100.0;

        $safe = 0;
        foreach ($matrix as $row) {
            if ($row['status'] === 'safe') $safe++;
        }

        return ($safe / $total) * 100;
    }
}
