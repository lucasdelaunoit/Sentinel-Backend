<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'fragility_tolerance' => ['sometimes', 'required', Rule::in(['conservative', 'balanced', 'aggressive'])],
            'fragility_weight_bus_factor' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'fragility_weight_uncovered_skills' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'fragility_weight_silos' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'fragility_weight_absence_impact' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'silo_threshold' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'kci_min_level' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'critical_bus_factor_threshold' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'absence_horizon_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'rule_violation_penalty' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ];
    }
}
