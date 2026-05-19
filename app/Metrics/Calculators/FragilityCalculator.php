<?php

namespace App\Metrics\Calculators;

use App\Models\Project;

/**
 * Computes org-level fragility KPI from cached Project.fragility_raw columns.
 * Returns the worst active project's score (the "headline" risk) plus an
 * insight line summarising how many projects fall in each tier.
 */
class FragilityCalculator
{
    /**
     * @return array{raw: int, insight: ?string}
     */
    public function compute(): array
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
}
