<?php

namespace App\Services;

use App\Enums\AbsenceHalf;
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
                isset($holidaySet[$iso])             => 'holiday',
                ($workingMask[$weekdayIdx] ?? 0) === 1 => 'working',
                default                              => 'off',
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
     *  Count working HALF-days in the inclusive absence range [start.startHalf … end.endHalf],
     *  excluding off-days and holidays. This is the "normalized" absence length — the real number
     *  of working days an absence consumes. Returns a float in 0.5 steps (a half-day = 0.5).
     *  Pure computation — caller provides settings + holidays so no DB access happens here.
     * </summary>
     *
     * @param mixed $startDate Absence first day (date|string|Carbon)
     * @param AbsenceHalf|string|null $startHalf Half the first day starts on (afternoon drops its morning)
     * @param mixed $endDate Absence last day
     * @param AbsenceHalf|string|null $endHalf Half the last day ends on (morning drops its afternoon)
     * @param OrganizationSetting $setting Singleton settings (working_days mask)
     * @param iterable<CompanyHoliday> $holidays All company holidays (recurring projected across the span)
     * @return float Working-day count in 0.5 steps
     */
    public function countWorkingHalfDays(
        mixed $startDate,
        AbsenceHalf|string|null $startHalf,
        mixed $endDate,
        AbsenceHalf|string|null $endHalf,
        OrganizationSetting $setting,
        iterable $holidays,
    ): float {
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end   = CarbonImmutable::parse($endDate)->startOfDay();
        if ($end->lt($start)) {
            return 0.0;
        }

        $workingMask = $setting->working_days ?? [1, 1, 1, 1, 1, 0, 0];
        $holidaySet  = $this->buildHolidayDateSetForRange($holidays, $start, $end);

        $startIsAfternoon = $this->halfValue($startHalf) === AbsenceHalf::Afternoon->value;
        $endIsMorning     = $this->halfValue($endHalf) === AbsenceHalf::Morning->value;
        $startIso         = $start->toDateString();
        $endIso           = $end->toDateString();

        $total = 0.0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $weekdayIdx = (int) $day->dayOfWeekIso - 1; // 0 = Monday
            $iso        = $day->toDateString();

            $isWorking = !isset($holidaySet[$iso]) && (($workingMask[$weekdayIdx] ?? 0) === 1);
            if (!$isWorking) {
                continue;
            }

            $halves = 2;
            if ($iso === $startIso && $startIsAfternoon) {
                $halves -= 1; // absence begins in the afternoon → no morning
            }
            if ($iso === $endIso && $endIsMorning) {
                $halves -= 1; // absence ends in the morning → no afternoon
            }
            $total += $halves * 0.5;
        }

        return $total;
    }

    private function halfValue(AbsenceHalf|string|null $half): ?string
    {
        return $half instanceof AbsenceHalf ? $half->value : $half;
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
            $start = CarbonImmutable::parse($holiday->start_date);
            $end   = CarbonImmutable::parse($holiday->end_date);
            if ($holiday->recurring) {
                $start = $start->setYear($year);
                $end   = $end->setYear($year);
                if ($end->lt($start)) {
                    $end = $end->addYear();
                }
            }
            foreach (CarbonPeriod::create($start, $end) as $day) {
                $set[$day->toDateString()] = true;
            }
        }
        return $set;
    }

    /**
     * <summary>
     *  Holiday date-set covering an arbitrary range, projecting recurring holidays onto every
     *  year the range spans (an absence may cross a year boundary).
     * </summary>
     *
     * @param iterable<CompanyHoliday> $holidays
     * @param CarbonImmutable $rangeStart Range first day
     * @param CarbonImmutable $rangeEnd Range last day
     * @return array<string, true>
     */
    private function buildHolidayDateSetForRange(iterable $holidays, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $set   = [];
        $years = range($rangeStart->year, $rangeEnd->year);

        foreach ($holidays as $holiday) {
            $start = CarbonImmutable::parse($holiday->start_date);
            $end   = CarbonImmutable::parse($holiday->end_date);

            if ($holiday->recurring) {
                foreach ($years as $year) {
                    $s = $start->setYear($year);
                    $e = $end->setYear($year);
                    if ($e->lt($s)) {
                        $e = $e->addYear();
                    }
                    foreach (CarbonPeriod::create($s, $e) as $day) {
                        $set[$day->toDateString()] = true;
                    }
                }
            } else {
                foreach (CarbonPeriod::create($start, $end) as $day) {
                    $set[$day->toDateString()] = true;
                }
            }
        }

        return $set;
    }
}
