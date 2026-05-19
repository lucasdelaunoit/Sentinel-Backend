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
                singular: 'active project',
                plural:   'active projects',
                severity: 'ok',
            ),
            'avg_trajectory' => StatCard::trajectory((int) $r['avg_trajectory_raw']),
            'fragile_count'  => StatCard::count(
                n:        (int) $r['critical_count'],
                singular: 'fragile project',
                plural:   'fragile projects',
                severity: $r['critical_count'] > 0 ? 'critical' : 'ok',
            ),
            'stretched_count' => StatCard::count(
                n:        (int) $r['stretched_count'],
                singular: 'stretched project',
                plural:   'stretched projects',
                severity: $r['stretched_count'] > 0 ? 'warning' : 'ok',
            ),
        ];
    }
}
