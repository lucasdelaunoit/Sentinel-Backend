<?php

namespace App\Http\Resources;

use App\DTO\Stats\ProjectsStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectsStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var ProjectsStats $stats */
        $stats = $this->resource;

        return $stats->toArray();
    }
}
