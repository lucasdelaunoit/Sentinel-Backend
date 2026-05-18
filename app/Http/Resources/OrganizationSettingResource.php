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
            'id'                            => $this->id,
            'name'                          => $this->name,
            'risk_tolerance'                => $this->risk_tolerance,
            'working_days'                  => $this->working_days,
            'risk_weight_bus_factor'        => $this->risk_weight_bus_factor,
            'risk_weight_uncovered_skills'  => $this->risk_weight_uncovered_skills,
            'risk_weight_silos'             => $this->risk_weight_silos,
            'risk_weight_absence_impact'    => $this->risk_weight_absence_impact,
            'silo_threshold'                => $this->silo_threshold,
            'kci_min_level'                 => $this->kci_min_level,
            'critical_bus_factor_threshold' => $this->critical_bus_factor_threshold,
            'health_risk_weight'            => $this->health_risk_weight,
            'absence_horizon_days'          => $this->absence_horizon_days,
            'rule_violation_penalty'        => $this->rule_violation_penalty,
        ];
    }
}
