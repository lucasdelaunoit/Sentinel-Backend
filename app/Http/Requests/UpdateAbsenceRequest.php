<?php

namespace App\Http\Requests;

use App\Models\Absence;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAbsenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'date'],
            'end_date'   => ['sometimes', 'date', 'after_or_equal:start_date'],
            'type'       => ['sometimes', 'in:vacation,sick,personal,other'],
            'reason'     => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            /** @var Absence $absence */
            $absence = $this->route('absence');

            if (!$this->has('start_date') && !$this->has('end_date')) {
                return;
            }

            $start = Carbon::parse($this->input('start_date', $absence->start_date))->toDateString();
            $end   = Carbon::parse($this->input('end_date', $absence->end_date))->toDateString();

            $overlap = Absence::query()
                ->where('user_id', $absence->user_id)
                ->where('id', '!=', $absence->id)
                ->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)
                ->exists();

            if ($overlap) {
                $v->errors()->add('start_date', 'This absence overlaps with an existing absence for this user.');
            }
        });
    }
}
