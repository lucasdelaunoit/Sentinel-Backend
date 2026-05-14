<?php

namespace App\Http\Requests;

use App\Models\Absence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAbsenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'type'       => ['nullable', 'in:vacation,sick,personal,other'],
            'reason'     => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            /** @var User $user */
            $user  = $this->route('user');
            $start = Carbon::parse($this->input('start_date'))->toDateString();
            $end   = Carbon::parse($this->input('end_date'))->toDateString();

            $overlap = Absence::query()
                ->where('user_id', $user->id)
                ->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)
                ->exists();

            if ($overlap) {
                $v->errors()->add('start_date', 'This absence overlaps with an existing absence for this user.');
            }
        });
    }
}
