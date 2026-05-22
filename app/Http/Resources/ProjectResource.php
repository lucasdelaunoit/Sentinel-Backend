<?php

namespace App\Http\Resources;

use App\Metrics\BusFactorScale;
use App\Metrics\FragilityScale;
use App\Metrics\Stat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $fragilityRaw = (int) $this->fragility_raw;
        $busFactor = (int) $this->bus_factor;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'fragility' => Stat::fromScale(
                FragilityScale::fromRaw($fragilityRaw),
                $fragilityRaw,
                "Score: {$fragilityRaw}/100",
            )->toArray(),
            'bus_factor' => Stat::fromScale(
                BusFactorScale::fromCount($busFactor),
                $busFactor,
                $busFactor > 0 ? "{$busFactor} key " . ($busFactor === 1 ? 'person' : 'people') : null,
            )->toArray(),
            'started_at' => $this->started_at,
            'deadline' => $this->deadline,
            'paused_at' => $this->paused_at,
            'completed_at' => $this->completed_at,
            'archived_at' => $this->archived_at,
            'users_count' => $this->when(isset($this->users_count), $this->users_count),
            'users' => $this->whenLoaded('users'),
            'skill_requirements' => $this->whenLoaded('skillRequirements'),
            'simulations' => $this->whenLoaded('simulations'),
            'created_at' => $this->created_at,
        ];
    }
}
