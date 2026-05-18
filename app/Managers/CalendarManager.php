<?php

namespace App\Managers;

use App\Models\OrganizationSetting;
use App\Services\CalendarService;
use App\Services\CompanyHolidayService;
use App\Services\OrganizationSettingService;
use Illuminate\Support\Facades\DB;
use Throwable;

class CalendarManager
{
    public function __construct(
        private readonly OrganizationSettingService $organizationSettingService,
        private readonly CompanyHolidayService      $companyHolidayService,
        private readonly CalendarService            $calendarService,
    ) {}

    /**
     * <summary>
     *  Update only the calendar-related fields on the singleton settings row inside a transaction.
     * </summary>
     *
     * @param array $data Validated payload — working_days, timezone, standard_days_month
     * @return OrganizationSetting Freshly reloaded settings row
     * @throws Throwable When the underlying DB transaction fails and is rolled back
     */
    public function updateCalendarSetting(array $data): OrganizationSetting
    {
        return DB::transaction(fn() => $this->organizationSettingService->updateCalendarSetting($data));
    }

    /**
     * <summary>
     *  Build a calendar summary for a given month — combines settings + holidays + computed counters.
     * </summary>
     *
     * @param int $year  Full year
     * @param int $month Month 1–12
     * @return array Summary payload consumed by the frontend Calendar tab
     */
    public function getCalendarSummary(int $year, int $month): array
    {
        $setting   = $this->organizationSettingService->getOrganizationSetting();
        $holidays  = $this->companyHolidayService->getCompanyHolidaysForMonth($year, $month);
        $preview   = $this->calendarService->buildMonthPreview($year, $month, $setting, $holidays);
        $working   = $this->calendarService->countWorkingDays($year, $month, $setting, $holidays);
        $perWeek   = (int) array_sum($setting->working_days ?? [1, 1, 1, 1, 1, 0, 0]);

        return [
            'year'                  => $year,
            'month'                 => $month,
            'working_days'          => $setting->working_days,
            'working_days_per_week' => $perWeek,
            'working_days_in_month' => $working,
            'company_holidays'      => $holidays,
            'preview'               => $preview,
        ];
    }
}
