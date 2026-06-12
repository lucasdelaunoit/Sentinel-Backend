<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationSettingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fragility_tolerance' => $this->fragility_tolerance,
            'fragility_weight_bus_factor' => $this->fragility_weight_bus_factor,
            'fragility_weight_uncovered_skills' => $this->fragility_weight_uncovered_skills,
            'fragility_weight_silos' => $this->fragility_weight_silos,
            'fragility_weight_absence_impact' => $this->fragility_weight_absence_impact,
            'silo_threshold' => $this->silo_threshold,
            'kci_min_level' => $this->kci_min_level,
            'critical_bus_factor_threshold' => $this->critical_bus_factor_threshold,
            'absence_horizon_days' => $this->absence_horizon_days,
            'rule_violation_penalty' => $this->rule_violation_penalty,
        ];
    }
}
