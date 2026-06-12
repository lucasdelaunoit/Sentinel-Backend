<?php

namespace App\Http\Requests;

use App\Enums\AbsenceHalf;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\CompanyHoliday;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\OrganizationSettingService;
use App\Support\AbsenceSlot;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAbsenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date'],
            'start_half' => ['nullable', Rule::enum(AbsenceHalf::class)],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'end_half' => ['nullable', Rule::enum(AbsenceHalf::class)],
            'type' => ['nullable', Rule::enum(AbsenceType::class)],
            'reason' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            /** @var User $user */
            $user = $this->route('user');
            $start = Carbon::parse($this->input('start_date'))->toDateString();
            $end = Carbon::parse($this->input('end_date'))->toDateString();

            $candidateStart = AbsenceSlot::start($start, $this->input('start_half'));
            $candidateEnd = AbsenceSlot::end($end, $this->input('end_half'));

            // Same-day PM→AM and similar half-day inversions.
            if ($candidateEnd < $candidateStart) {
                $v->errors()->add('end_date', 'End must be on or after the start, including the half-day.');

                return;
            }

            // Reject ranges that contain no working day at all (only weekends / holidays).
            $workingHalfDays = app(CalendarService::class)->countWorkingHalfDays(
                $start,
                $this->input('start_half'),
                $end,
                $this->input('end_half'),
                app(OrganizationSettingService::class)->getOrganizationSetting(),
                CompanyHoliday::all(),
            );

            if ($workingHalfDays <= 0) {
                $v->errors()->add('start_date', 'This absence covers only weekends and/or holidays — it must include at least one working day.');

                return;
            }

            // Date-overlap narrows the set; halves refine it (AM + PM on one day do not clash).
            $candidates = Absence::query()
                ->where('user_id', $user->id)
                ->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)
                ->get(['start_date', 'start_half', 'end_date', 'end_half']);

            foreach ($candidates as $existing) {
                $existingStart = AbsenceSlot::start($existing->start_date, $existing->start_half);
                $existingEnd = AbsenceSlot::end($existing->end_date, $existing->end_half);

                if (AbsenceSlot::overlaps($candidateStart, $candidateEnd, $existingStart, $existingEnd)) {
                    $v->errors()->add('start_date', 'This absence overlaps with an existing absence for this user.');

                    return;
                }
            }
        });
    }
}