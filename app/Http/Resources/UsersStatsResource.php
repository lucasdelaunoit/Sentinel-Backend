<?php

namespace App\Http\Resources;

use App\DTO\Stats\UsersStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsersStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var UsersStats $stats */
        $stats = $this->resource;

        return $stats->toArray();
    }
}
