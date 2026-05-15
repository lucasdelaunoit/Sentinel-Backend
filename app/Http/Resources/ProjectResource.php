<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'risk_score' => $this->risk_score,
            'bus_factor' => $this->bus_factor,
            'health' => $this->health,
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
