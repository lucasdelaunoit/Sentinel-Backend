<?php

namespace App\Http\Resources;

use App\Support\MetricPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $r       = $this->resource;
        $team    = $r['team'];
        $present = (int) $team['total'] - (int) $team['away'];

        return [
            'fragility'  => MetricPresenter::fragility((float) $r['fragility_raw']),
            'bus_factor' => MetricPresenter::busFactor((int) $r['bus_factor']),
            'trajectory' => MetricPresenter::trajectory((float) $r['trajectory_raw']),
            'team'       => MetricPresenter::ratio(
                a:        $present,
                b:        (int) $team['total'],
                severity: $team['away'] > 0 ? 'warning' : 'ok',
                hint:     $team['away'] > 0 ? "{$team['away']} away today" : 'Full team',
            ),
        ];
    }
}
