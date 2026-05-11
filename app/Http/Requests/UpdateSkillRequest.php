<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSkillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'              => ['sometimes', 'string', 'max:255'],
            'skill_category_id' => ['sometimes', 'integer', 'exists:skill_categories,id'],
        ];
    }
}
