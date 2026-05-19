<?php

namespace App\Http\Resources;

use App\Support\StatCard;
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
            'fragility'  => StatCard::fragility((float) $r['fragility_raw']),
            'bus_factor' => StatCard::busFactor((int) $r['bus_factor']),
            'trajectory' => StatCard::trajectory((float) $r['trajectory_raw']),
            'team'       => StatCard::make(
                value:    "{$present}/{$team['total']} present",
                severity: $team['away'] > 0 ? 'warning' : 'ok',
                raw:      $present,
                hint:     $team['away'] > 0 ? "{$team['away']} away today" : 'Full team',
            ),
        ];
    }
}
