<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyHolidayRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'date'      => ['required', 'date'],
            'recurring' => ['sometimes', 'boolean'],
        ];
    }
}
