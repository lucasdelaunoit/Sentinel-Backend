<?php

namespace App\Http\Resources;

use App\DTO\Stats\ProjectStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var ProjectStats $stats */
        $stats = $this->resource;

        return $stats->toArray();
    }
}
