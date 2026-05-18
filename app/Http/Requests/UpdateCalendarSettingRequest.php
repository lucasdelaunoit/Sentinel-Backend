<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'working_days'   => ['sometimes', 'required', 'array', 'size:7'],
            'working_days.*' => ['integer', 'in:0,1'],
        ];
    }
}
