<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimulatePlanningRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'absences'                => ['required', 'array', 'min:1'],
            'absences.*.user_id'      => ['required', 'integer', 'exists:users,id'],
            'absences.*.start_date'   => ['required', 'date'],
            'absences.*.end_date'     => ['required', 'date', 'after_or_equal:absences.*.start_date'],
            'absences.*.start_half'   => ['nullable', 'integer', 'in:0,1'],
            'absences.*.end_half'     => ['nullable', 'integer', 'in:0,1'],
            'month'                   => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }
}
