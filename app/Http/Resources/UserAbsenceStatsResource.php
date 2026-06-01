<?php

namespace App\Http\Resources;

use App\DTO\Stats\UserAbsenceStats;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAbsenceStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var UserAbsenceStats $stats */
        $stats = $this->resource;

        return $stats->toArray();
    }
}
