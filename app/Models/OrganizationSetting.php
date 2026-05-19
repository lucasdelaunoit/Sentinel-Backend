<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationSetting extends Model
{
    protected $fillable = [
        'name',
        'fragility_tolerance',
        'working_days',
        'fragility_weight_bus_factor',
        'fragility_weight_uncovered_skills',
        'fragility_weight_silos',
        'fragility_weight_absence_impact',
        'silo_threshold',
        'kci_min_level',
        'critical_bus_factor_threshold',
        'absence_horizon_days',
        'rule_violation_penalty',
    ];

    protected $casts = [
        'working_days'                       => 'array',
        'fragility_weight_bus_factor'        => 'integer',
        'fragility_weight_uncovered_skills'  => 'integer',
        'fragility_weight_silos'             => 'integer',
        'fragility_weight_absence_impact'    => 'integer',
        'silo_threshold'                     => 'integer',
        'kci_min_level'                      => 'integer',
        'critical_bus_factor_threshold'      => 'integer',
        'absence_horizon_days'               => 'integer',
        'rule_violation_penalty'             => 'integer',
    ];
}
