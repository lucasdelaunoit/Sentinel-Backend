<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'industry'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'size'           => ['sometimes', 'nullable', Rule::in(['1-10', '11-50', '51-200', '201-500', '500+'])],
            'location'       => ['sometimes', 'nullable', 'string', 'max:100'],
            'methodology'    => ['sometimes', 'required', Rule::in(['agile', 'waterfall', 'kanban', 'scrumban'])],
            'team_structure' => ['sometimes', 'required', Rule::in(['cross-functional', 'functional', 'matrix', 'squad'])],
            'risk_tolerance' => ['sometimes', 'required', Rule::in(['conservative', 'balanced', 'aggressive'])],
        ];
    }
}
