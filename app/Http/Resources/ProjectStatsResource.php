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
            'risk_score'   => $this->resource['risk_score'],
            'bus_factor'   => $this->resource['bus_factor'],
            'health_score' => $this->resource['health_score'],
            'team'         => $this->resource['team'],
        ];
    }
}
