<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'fragility_raw'  => $this->resource['fragility_raw'],
            'fragility'      => $this->resource['fragility'],
            'bus_factor'     => $this->resource['bus_factor'],
            'trajectory_raw' => $this->resource['trajectory_raw'],
            'trajectory'     => $this->resource['trajectory'],
            'team'           => $this->resource['team'],
        ];
    }
}
