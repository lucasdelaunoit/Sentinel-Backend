<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCalendarSettingRequest;
use App\Http\Resources\CalendarSummaryResource;
use App\Http\Resources\OrganizationSettingResource;
use App\Managers\CalendarManager;
use App\Services\CalendarImpactService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     *  Return only working_days bit array (7 ints, Mon-Sun).
     * </summary>
     *
     * @return JsonResponse { working_days: int[] }
     */
    public function getWorkingDays(): JsonResponse
    {
        // Act (Manager)
        $workingDays = $this->calendarManager->getWorkingDays();

        // Return (Controller)
        return response()->json($workingDays);
    }

    /**
     * <summary>
     *  Preview which FUTURE absences would have their working-day count changed by a pending
     *  calendar change (working week, holiday create/update) — without applying it. The frontend
     *  uses this to ask the user what to do before committing the change.
     * </summary>
     *
     * @param Request $request type + change payload
     * @param CalendarImpactService $impact Impact computation service
     * @return JsonResponse { affected: [...] }
     */
    public function previewImpact(Request $request, CalendarImpactService $impact): JsonResponse
    {
        // Validate & authorize (Controller)
        $data = $request->validate([
            'type' => ['required', Rule::in(['working_days', 'holiday_create', 'holiday_update'])],
            'working_days' => ['required_if:type,working_days', 'array', 'size:7'],
            'working_days.*' => ['integer', 'in:0,1'],
            'holiday' => ['required_if:type,holiday_create,holiday_update', 'array'],
            'holiday.name' => ['nullable', 'string', 'max:255'],
            'holiday.start_date' => ['required_with:holiday', 'date'],
            'holiday.end_date' => ['required_with:holiday', 'date', 'after_or_equal:holiday.start_date'],
            'holiday.recurring' => ['nullable', 'boolean'],
            'holiday_id' => ['required_if:type,holiday_update', 'integer'],
        ]);

        // Act (Manager)
        $affected = $impact->previewForChange($data['type'], $data);

        // Return (Controller)
        return response()->json(['affected' => $affected]);
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
        // Validate & authorize (Controller)
        $now = CarbonImmutable::now();
        $year = (int) $request->query('year', $now->year);
        $month = (int) $request->query('month', $now->month);

        // Act (Manager)
        $summary = $this->calendarManager->getCalendarSummary($year, $month);

        // Return (Controller)
        return new CalendarSummaryResource($summary);
    }
}
