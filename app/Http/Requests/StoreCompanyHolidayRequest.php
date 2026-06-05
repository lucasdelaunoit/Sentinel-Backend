<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyHolidayRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'recurring'  => ['sometimes', 'boolean'],
            // Future absences the user chose to KEEP (freeze at current count) before this change.
            'freeze_absence_ids'   => ['sometimes', 'array'],
            'freeze_absence_ids.*' => ['integer'],
        ];
    }
}
