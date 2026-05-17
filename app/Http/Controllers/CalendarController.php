<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCalendarSettingRequest;
use App\Http\Resources\CalendarSummaryResource;
use App\Http\Resources\OrganizationSettingResource;
use App\Managers\CalendarManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarManager $calendarManager,
    ) {}

    /**
     * <summary>
     *  Update calendar fields (working_days, timezone, standard_days_month) on the singleton settings row.
     * </summary>
     *
     * @param UpdateCalendarSettingRequest $request Validated payload
     * @return OrganizationSettingResource Updated settings row
     */
    public function updateCalendarSetting(UpdateCalendarSettingRequest $request): OrganizationSettingResource
    {
        // Act (Manager)
        $setting = $this->calendarManager->updateCalendarSetting($request->validated());

        // Return (Controller)
        return new OrganizationSettingResource($setting);
    }

    /**
     * <summary>
     *  Return a calendar summary for the requested month (defaults to current month).
     *  ?year=YYYY&month=M — both optional.
     * </summary>
     *
     * @param Request $request HTTP request — optional year/month query params
     * @return CalendarSummaryResource
     */
    public function getCalendarSummary(Request $request): CalendarSummaryResource
    {
        // Act (Manager)
        $now     = CarbonImmutable::now();
        $year    = (int) $request->query('year', $now->year);
        $month   = (int) $request->query('month', $now->month);
        $summary = $this->calendarManager->getCalendarSummary($year, $month);

        // Return (Controller)
        return new CalendarSummaryResource($summary);
    }
}
