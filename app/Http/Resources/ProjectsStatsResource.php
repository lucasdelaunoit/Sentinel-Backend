<?php

namespace App\Http\Resources;

use App\Support\MetricPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectsStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'total' => MetricPresenter::count(
                n:        (int) $r['total'],
                severity: 'ok',
                zeroLabel: 'None',
                hint:      'Active projects',
            ),
            'avg_fragility' => MetricPresenter::fragility((int) $r['avg_fragility_raw']),
            'fragile_count' => MetricPresenter::count(
                n:         (int) $r['critical_count'],
                severity:  $r['critical_count'] > 0 ? 'critical' : 'ok',
                zeroLabel: 'Healthy',
                hint:      $r['critical_count'] > 0 ? 'Fragility &gt; 60' : null,
            ),
            'stretched_count' => MetricPresenter::count(
                n:         (int) $r['stretched_count'],
                severity:  $r['stretched_count'] > 0 ? 'warning' : 'ok',
                zeroLabel: 'None',
                hint:      $r['stretched_count'] > 0 ? 'Fragility 41-60' : null,
            ),
        ];
    }
}
