<?php

namespace App\Http\Resources;

use App\DTO\CalculationSyncStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalculationSyncStatusResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var CalculationSyncStatus $status */
        $status = $this->resource;

        return $status->toArray();
    }
}
