<?php

namespace App\Http\Resources;

use App\Support\StatCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'description'        => $this->description,
            'status'             => $this->status,
            'fragility'          => StatCard::fragility((int) $this->fragility_raw),
            'bus_factor'         => StatCard::busFactor((int) $this->bus_factor),
            'trajectory'         => StatCard::trajectory((int) $this->trajectory_raw),
            'started_at'         => $this->started_at,
            'deadline'           => $this->deadline,
            'paused_at'          => $this->paused_at,
            'completed_at'       => $this->completed_at,
            'archived_at'        => $this->archived_at,
            'users_count'        => $this->when(isset($this->users_count), $this->users_count),
            'users'              => $this->whenLoaded('users'),
            'skill_requirements' => $this->whenLoaded('skillRequirements'),
            'simulations'        => $this->whenLoaded('simulations'),
            'created_at'         => $this->created_at,
        ];
    }
}
