<?php

namespace App\Http\Resources;

use App\Services\AbsenceNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AbsenceResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        // Memoized singleton — settings + holidays load once across the whole collection.
        $normalizer = app(AbsenceNormalizer::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'start_date' => $this->start_date?->toDateString(),
            'start_half' => $this->start_half?->value,
            'end_date' => $this->end_date?->toDateString(),
            'end_half' => $this->end_half?->value,
            'type' => $this->type?->value,
            'reason' => $this->reason,
            // Raw calendar span vs real working days consumed (weekends/holidays removed).
            'total_days' => $normalizer->totalDays($this->resource),
            'normalized_days' => $normalizer->resolve($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
