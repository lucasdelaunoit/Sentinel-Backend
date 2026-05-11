<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSkillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'skill_category_id' => ['required', 'integer', 'exists:skill_categories,id'],
        ];
    }
}
