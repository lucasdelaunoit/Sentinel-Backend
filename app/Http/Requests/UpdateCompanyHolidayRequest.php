<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyHolidayRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:255'],
            'date'      => ['sometimes', 'required', 'date'],
            'recurring' => ['sometimes', 'boolean'],
        ];
    }
}
