<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['nullable', 'in:active,paused,completed,archived'],
            'progress'    => ['nullable', 'integer', 'min:0', 'max:100'],
            'started_at'  => ['nullable', 'date'],
            'ended_at'    => ['nullable', 'date', 'after_or_equal:started_at'],
        ];
    }
}
