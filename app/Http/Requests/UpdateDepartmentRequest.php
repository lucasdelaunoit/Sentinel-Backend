<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function rules(): array
    {
        $department = $this->route('department');
        $departmentId = $department?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('departments', 'name')->ignore($departmentId),
            ],
        ];
    }
}
