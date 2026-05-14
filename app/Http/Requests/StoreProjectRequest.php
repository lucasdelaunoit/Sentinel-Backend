<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('started_at')) {
            $this->merge(['started_at' => now()->toDateString()]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects', 'name')->whereNull('deleted_at'),
            ],
            'description' => ['required', 'string', 'max:5000'],
            'started_at' => ['required', 'date'],
            'deadline' => ['nullable', 'date', 'after_or_equal:started_at'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'skill_requirements' => ['sometimes', 'array'],
            'skill_requirements.*.skill_id' => ['required', 'integer', 'exists:skills,id', 'distinct'],
            'skill_requirements.*.required_level' => ['required', 'integer', 'min:1', 'max:5'],
        ];
    }
}
