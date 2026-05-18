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
            'total'              => $this->resource['total'],
            'avg_trajectory_raw' => $this->resource['avg_trajectory_raw'],
            'avg_trajectory'     => $this->resource['avg_trajectory'],
            'critical_count'     => $this->resource['critical_count'],
            'stretched_count'    => $this->resource['stretched_count'],
        ];
    }
}
