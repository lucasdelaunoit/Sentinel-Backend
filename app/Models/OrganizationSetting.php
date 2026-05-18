<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationSetting extends Model
{
    protected $fillable = [
        'name',
        'risk_tolerance',
        'working_days',
        'risk_weight_bus_factor',
        'risk_weight_uncovered_skills',
        'risk_weight_silos',
        'risk_weight_absence_impact',
        'silo_threshold',
        'kci_min_level',
        'critical_bus_factor_threshold',
        'health_risk_weight',
        'absence_horizon_days',
        'rule_violation_penalty',
    ];

    protected $casts = [
        'working_days'                  => 'array',
        'risk_weight_bus_factor'        => 'integer',
        'risk_weight_uncovered_skills'  => 'integer',
        'risk_weight_silos'             => 'integer',
        'risk_weight_absence_impact'    => 'integer',
        'silo_threshold'                => 'integer',
        'kci_min_level'                 => 'integer',
        'critical_bus_factor_threshold' => 'integer',
        'health_risk_weight'            => 'integer',
        'absence_horizon_days'          => 'integer',
        'rule_violation_penalty'        => 'integer',
    ];
}
