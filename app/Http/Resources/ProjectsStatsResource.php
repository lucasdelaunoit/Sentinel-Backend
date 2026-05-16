<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectsStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'total'      => $this->resource['total'],
            'avg_health' => $this->resource['avg_health'],
            'fragile'    => $this->resource['fragile'],
            'at_risk'    => $this->resource['at_risk'],
        ];
    }
}
