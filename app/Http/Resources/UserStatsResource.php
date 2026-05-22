<?php

namespace App\Http\Resources;

use App\Support\UserStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var UserStats $stats */
        $stats = $this->resource;

        return $stats->toArray();
    }
}
