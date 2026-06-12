<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachSkillToProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'required_level' => ['required', 'integer', 'min:1', 'max:5'],
        ];
    }
}
