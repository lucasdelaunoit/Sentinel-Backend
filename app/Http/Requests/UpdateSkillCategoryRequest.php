<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSkillCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        $categoryId = $this->route('skillCategory')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('skill_categories', 'name')->ignore($categoryId)->whereNull('deleted_at'),
            ],
        ];
    }
}
