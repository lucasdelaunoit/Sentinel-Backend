<?php

namespace App\Http\Resources;

use App\Support\StatCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectsStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'total' => StatCard::count(
                n:        (int) $r['total'],
                severity: 'ok',
                zeroLabel: 'None',
                hint:      'Active projects',
            ),
            'avg_trajectory' => StatCard::trajectory((int) $r['avg_trajectory_raw']),
            'fragile_count' => StatCard::count(
                n:         (int) $r['critical_count'],
                severity:  $r['critical_count'] > 0 ? 'critical' : 'ok',
                zeroLabel: 'Healthy',
                hint:      $r['critical_count'] > 0 ? 'Fragility &gt; 60' : null,
            ),
            'stretched_count' => StatCard::count(
                n:         (int) $r['stretched_count'],
                severity:  $r['stretched_count'] > 0 ? 'warning' : 'ok',
                zeroLabel: 'None',
                hint:      $r['stretched_count'] > 0 ? 'Fragility 41-60' : null,
            ),
        ];
    }
}
