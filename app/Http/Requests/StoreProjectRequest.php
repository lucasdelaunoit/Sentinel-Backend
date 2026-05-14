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
            'started_at'  => ['nullable', 'date'],
            'deadline'    => ['nullable', 'date', 'after_or_equal:started_at'],
        ];
    }
}
