<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'in:active,paused,completed,archived'],
            'progress'    => ['sometimes', 'integer', 'min:0', 'max:100'],
            'started_at'  => ['nullable', 'date'],
            'ended_at'    => ['nullable', 'date', 'after_or_equal:started_at'],
        ];
    }
}
