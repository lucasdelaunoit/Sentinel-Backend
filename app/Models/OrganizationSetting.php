<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationSetting extends Model
{
    protected $fillable = [
        'name',
        'industry',
        'size',
        'location',
        'methodology',
        'team_structure',
        'risk_tolerance',
        'working_days',
        'timezone',
        'standard_days_month',
    ];

    protected $casts = [
        'working_days'        => 'array',
        'standard_days_month' => 'integer',
    ];
}
