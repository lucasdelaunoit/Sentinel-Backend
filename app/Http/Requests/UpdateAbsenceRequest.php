<?php

namespace App\Http\Requests;

use App\Enums\AbsenceHalf;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\CompanyHoliday;
use App\Services\CalendarService;
use App\Services\OrganizationSettingService;
use App\Support\AbsenceSlot;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAbsenceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'date'],
            'start_half' => ['sometimes', Rule::enum(AbsenceHalf::class)],
            'end_date'   => ['sometimes', 'date', 'after_or_equal:start_date'],
            'end_half'   => ['sometimes', Rule::enum(AbsenceHalf::class)],
            'type' => ['sometimes', Rule::enum(AbsenceType::class)],
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

            // Effective values after the patch (fall back to the stored row).
            $start     = Carbon::parse($this->input('start_date', $absence->start_date))->toDateString();
            $end       = Carbon::parse($this->input('end_date', $absence->end_date))->toDateString();
            $startHalf = $this->input('start_half', $absence->start_half);
            $endHalf   = $this->input('end_half', $absence->end_half);

            $candidateStart = AbsenceSlot::start($start, $startHalf);
            $candidateEnd   = AbsenceSlot::end($end, $endHalf);

            if ($candidateEnd < $candidateStart) {
                $v->errors()->add('end_date', 'End must be on or after the start, including the half-day.');

                return;
            }

            // Reject ranges that contain no working day at all (only weekends / holidays).
            $workingHalfDays = app(CalendarService::class)->countWorkingHalfDays(
                $start,
                $startHalf,
                $end,
                $endHalf,
                app(OrganizationSettingService::class)->getOrganizationSetting(),
                CompanyHoliday::all(),
            );

            if ($workingHalfDays <= 0) {
                $v->errors()->add('start_date', 'This absence covers only weekends and/or holidays — it must include at least one working day.');

                return;
            }

            $candidates = Absence::query()
                ->where('user_id', $absence->user_id)
                ->where('id', '!=', $absence->id)
                ->where('start_date', '<=', $end)
                ->where('end_date', '>=', $start)
                ->get(['start_date', 'start_half', 'end_date', 'end_half']);

            foreach ($candidates as $existing) {
                $existingStart = AbsenceSlot::start($existing->start_date, $existing->start_half);
                $existingEnd   = AbsenceSlot::end($existing->end_date, $existing->end_half);

                if (AbsenceSlot::overlaps($candidateStart, $candidateEnd, $existingStart, $existingEnd)) {
                    $v->errors()->add('start_date', 'This absence overlaps with an existing absence for this user.');

                    return;
                }
            }
        });
    }
}
