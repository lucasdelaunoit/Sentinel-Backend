<?php

namespace App\Services;

use App\Models\CompanyHoliday;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class CalendarService
{
    /**
     * <summary>
     *  Compute the working / holiday / off status for every day in the given month.
     *  Pure computation — caller provides settings + holidays so no DB access happens here.
     * </summary>
     *
     * @param int $year  Full year (e.g. 2026)
     * @param int $month Month 1–12
     * @param OrganizationSetting $setting Singleton settings (working_days mask)
     * @param iterable<CompanyHoliday> $holidays Holidays applicable to the month
     * @return array<int, array{date: string, day: int, weekday: int, status: string}>
     */
    public function buildMonthPreview(int $year, int $month, OrganizationSetting $setting, iterable $holidays): array
    {
        $workingMask = $setting->working_days ?? [1, 1, 1, 1, 1, 0, 0];
        $holidaySet  = $this->buildHolidayDateSet($holidays, $year);

        $start = CarbonImmutable::create($year, $month, 1);
        $end   = $start->endOfMonth();
        $days  = [];

        foreach (CarbonPeriod::create($start, $end) as $day) {
            $weekdayIdx = (int) $day->dayOfWeekIso - 1; // 0 = Monday
            $iso        = $day->toDateString();

            $status = match (true) {
                isset($holidaySet[$iso])           => 'holiday',
                ($workingMask[$weekdayIdx] ?? 0)   => 'working',
                default                            => 'off',
            };

            $days[] = [
                'date'    => $iso,
                'day'     => $day->day,
                'weekday' => $weekdayIdx,
                'status'  => $status,
            ];
        }

        return $days;
    }

    /**
     * <summary>
     *  Count the number of working days in a given month, excluding off-days and holidays.
     * </summary>
     *
     * @param int $year  Full year
     * @param int $month Month 1–12
     * @param OrganizationSetting $setting Singleton settings
     * @param iterable<CompanyHoliday> $holidays Holidays applicable to the month
     * @return int Working-day count
     */
    public function countWorkingDays(int $year, int $month, OrganizationSetting $setting, iterable $holidays): int
    {
        return Collection::make($this->buildMonthPreview($year, $month, $setting, $holidays))
            ->where('status', 'working')
            ->count();
    }

    /**
     * <summary>
     *  Build a date-keyed lookup for holidays. Recurring holidays are projected onto the requested year.
     * </summary>
     *
     * @param iterable<CompanyHoliday> $holidays
     * @param int $year Year used to project recurring holidays
     * @return array<string, true>
     */
    private function buildHolidayDateSet(iterable $holidays, int $year): array
    {
        $set = [];
        foreach ($holidays as $holiday) {
            $date = $holiday->recurring
                ? $holiday->date->setYear($year)
                : $holiday->date;
            $set[$date->toDateString()] = true;
        }
        return $set;
    }
}
