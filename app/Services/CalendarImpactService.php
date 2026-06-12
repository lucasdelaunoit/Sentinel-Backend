<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\CompanyHoliday;
use App\Models\OrganizationSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes how a *pending* calendar change (working week or holidays) would affect the
 * working-day count of FUTURE, still-fluid absences — and freezes the chosen ones so the
 * change does not retroactively recount them. See absence-normalized-count-policy: only
 * upcoming, non-frozen absences are in play; past/approved ones are already settled.
 */
class CalendarImpactService
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly OrganizationSettingService $settings,
    ) {}

    /**
     * <summary>
     *  Build the affected-absence preview for a proposed change, assembling the "future" calendar
     *  from the change type, then diffing each future absence's working-day count.
     * </summary>
     *
     * @param string $type One of: working_days, holiday_create, holiday_update
     * @param array<string, mixed> $payload Change payload (working_days | holiday | holiday_id)
     * @return array<int, array<string, mixed>> Affected absences with before/after counts
     */
    public function previewForChange(string $type, array $payload): array
    {
        $current = $this->settings->getOrganizationSetting();
        $currentWorking = $current->working_days ?? [1, 1, 1, 1, 1, 0, 0];
        $allHolidays = CompanyHoliday::all();

        return match ($type) {
            'working_days' => $this->preview($payload['working_days'], $allHolidays),
            'holiday_create' => $this->preview($currentWorking, $allHolidays->concat([$this->makeTransientHoliday($payload['holiday'])])),
            'holiday_update' => $this->preview(
                $currentWorking,
                $allHolidays->map(fn(CompanyHoliday $h) => $h->id === (int) ($payload['holiday_id'] ?? 0)
                    ? $this->makeTransientHoliday($payload['holiday'])
                    : $h),
            ),
            default => [],
        };
    }

    /**
     * <summary>
     *  Future, still-fluid absences whose working-day count differs between the CURRENT calendar
     *  and the proposed one.
     * </summary>
     *
     * @param array<int, int> $futureWorkingDays Proposed working-day mask (ISO Mon=0 … Sun=6)
     * @param Collection<int, CompanyHoliday> $futureHolidays Proposed holiday set
     * @return array<int, array<string, mixed>>
     */
    public function preview(array $futureWorkingDays, Collection $futureHolidays): array
    {
        $current = $this->settings->getOrganizationSetting();
        $currentHolidays = CompanyHoliday::all();

        $futureSetting = new OrganizationSetting();
        $futureSetting->working_days = array_values($futureWorkingDays);

        $out = [];
        foreach ($this->getFutureFluidAbsences() as $absence) {
            $before = $this->countWorkingDays($absence, $current, $currentHolidays);
            $after = $this->countWorkingDays($absence, $futureSetting, $futureHolidays);

            if ($before === $after) {
                continue;
            }

            $out[] = [
                'absence_id' => $absence->id,
                'user_id' => $absence->user_id,
                'user_name' => $absence->user
                    ? trim(($absence->user->firstname ?? '') . ' ' . ($absence->user->lastname ?? ''))
                    : 'Unknown',
                'start_date' => Carbon::parse($absence->start_date)->toDateString(),
                'end_date' => Carbon::parse($absence->end_date)->toDateString(),
                'before_days' => $before,
                'after_days' => $after,
            ];
        }

        return $out;
    }

    /**
     * <summary>
     *  Freeze the given absences at their CURRENT working-day count. Call this BEFORE persisting a
     *  calendar change (inside the same transaction) so the snapshot captures the pre-change value
     *  — those absences then keep their count while every other future absence recounts live.
     * </summary>
     *
     * @param array<int, int|string> $absenceIds Absence ids to freeze
     * @return void
     */
    public function freeze(array $absenceIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $absenceIds)));
        if (empty($ids)) {
            return;
        }

        $current = $this->settings->getOrganizationSetting();
        $holidays = CompanyHoliday::all();

        $absences = Absence::query()
            ->whereIn('id', $ids)
            ->whereNull('normalized_frozen_at')
            ->get();

        foreach ($absences as $absence) {
            $value = $this->countWorkingDays($absence, $current, $holidays);

            $absence->timestamps = false;
            $absence->forceFill([
                'normalized_days' => $value,
                'normalized_frozen_at' => Carbon::now(),
            ])->saveQuietly();
            $absence->timestamps = true;
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Absence> */
    private function getFutureFluidAbsences()
    {
        return Absence::query()
            ->with('user')
            ->whereDate('start_date', '>', Carbon::today())
            ->whereNull('normalized_frozen_at')
            ->orderBy('start_date')
            ->get();
    }

    private function countWorkingDays(Absence $absence, OrganizationSetting $setting, Collection $holidays): float
    {
        return $this->calendar->countWorkingHalfDays(
            $absence->start_date,
            $absence->start_half,
            $absence->end_date,
            $absence->end_half,
            $setting,
            $holidays,
        );
    }

    private function makeTransientHoliday(array $data): CompanyHoliday
    {
        $holiday = new CompanyHoliday();
        $holiday->name = $data['name'] ?? 'Holiday';
        $holiday->start_date = $data['start_date'];
        $holiday->end_date = $data['end_date'];
        $holiday->recurring = (bool) ($data['recurring'] ?? false);

        return $holiday;
    }
}
