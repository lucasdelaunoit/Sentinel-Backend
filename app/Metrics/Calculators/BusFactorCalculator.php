<?php

namespace App\Metrics\Calculators;

use App\Models\Project;
use App\Services\SkillCoverageService;

/**
 * Raw bus-factor calculation for a project.
 *
 * Bus factor = min users across required skills whose loss would break coverage.
 * Returns 0 when project already has any uncovered required skill.
 *
 * Accepts optional $absentUserIds so the same code path serves the live state
 * AND simulation runs (virtual absence roster).
 */
class BusFactorCalculator
{
    public function __construct(
        private readonly SkillCoverageService $coverage,
    ) {}

    /**
     * <summary>
     *  Compute the raw bus factor (int >= 0) for a project.
     * </summary>
     *
     * @param Project $project Target project
     * @param array<int> $absentUserIds Virtual absence roster (simulation). Empty for live state.
     * @return int
     */
    public function calculate(Project $project, array $absentUserIds = []): int
    {
        $matrix = $this->coverage->getCoverage($project, $absentUserIds);

        $coveredCounts = [];
        foreach ($matrix as $row) {
            $c = count($row['employees']);
            if ($c > 0) $coveredCounts[] = $c;
        }

        if ($coveredCounts === []) return 0;
        return min($coveredCounts);
    }
}
