<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCalendarSettingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'working_days'        => ['sometimes', 'required', 'array', 'size:7'],
            'working_days.*'      => ['integer', 'in:0,1'],
            // Future absences the user chose to KEEP (freeze at current count) before this change.
            'freeze_absence_ids'   => ['sometimes', 'array'],
            'freeze_absence_ids.*' => ['integer'],
        ];
    }
}
