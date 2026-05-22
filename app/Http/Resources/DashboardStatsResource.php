<?php

namespace App\Http\Resources;

use App\DTO\Stats\DashboardStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var DashboardStats $stats */
        $stats = $this->resource;

        return $stats->toArray();
    }
}
