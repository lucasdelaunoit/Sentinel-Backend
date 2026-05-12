<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSkillRequest extends FormRequest
{
    public function rules(): array
    {
        $skill = $this->route('skill');
        $skillId = $skill?->id;
        $targetCategory = $this->input('skill_category_id', $skill?->skill_category_id);

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('skills', 'name')
                    ->where('skill_category_id', $targetCategory)
                    ->whereNull('deleted_at')
                    ->ignore($skillId),
            ],
            'skill_category_id' => [
                'sometimes',
                'integer',
                Rule::exists('skill_categories', 'id')->whereNull('deleted_at'),
            ],
        ];
    }
}
