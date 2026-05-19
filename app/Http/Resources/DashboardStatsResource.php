<?php

namespace App\Http\Resources;

use App\Support\Stat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardStatsResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return array_map(fn(Stat $s) => $s->toArray(), $this->resource);
    }
}
