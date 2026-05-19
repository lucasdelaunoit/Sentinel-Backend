<?php

namespace App\Http\Resources;

use App\Support\MetricPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r = $this->resource;

        $fp = $r['fragile_projects'];
        $kc = $r['knowledge_coverage'];
        $ta = $r['team_availability'];
        $ai = $r['absence_impact'];

        return [
            'fragile_projects'   => MetricPresenter::count(
                n:         (int) $fp['value'],
                severity:  $fp['severity'],
                zeroLabel: 'Healthy',
                hint:      $fp['insight'] ?? null,
            ),
            'knowledge_coverage' => MetricPresenter::percentage(
                pct:  (int) $kc['value'],
                hint: $kc['insight'] ?? null,
            ),
            'team_availability'  => MetricPresenter::ratio(
                a:        (int) ($ta['available'] ?? 0),
                b:        (int) ($ta['total']     ?? 0),
                severity: $ta['severity'],
                hint:     $ta['insight'] ?? null,
            ),
            'absence_impact'     => MetricPresenter::count(
                n:         (int) $ai['value'],
                severity:  $ai['severity'],
                zeroLabel: 'None',
                hint:      $ai['insight'] ?? null,
            ),
        ];
    }
}
