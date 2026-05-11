<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'criticality' => $this->resource['criticality'],
            'bus_factor_in_org' => $this->resource['bus_factor_in_org'],
            'skills' => $this->resource['skills'],
            'active_projects' => $this->resource['active_projects'],
        ];
    }
}
