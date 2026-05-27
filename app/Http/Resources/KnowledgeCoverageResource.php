<?php

namespace App\Http\Resources;

use App\DTO\Stats\KnowledgeCoverageBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeCoverageResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var KnowledgeCoverageBreakdown $breakdown */
        $breakdown = $this->resource;

        return $breakdown->toArray();
    }
}
