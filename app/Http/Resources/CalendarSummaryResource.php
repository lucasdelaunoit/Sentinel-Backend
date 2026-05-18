<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'year'                  => $this['year'],
            'month'                 => $this['month'],
            'working_days'          => $this['working_days'],
            'working_days_per_week' => $this['working_days_per_week'],
            'working_days_in_month' => $this['working_days_in_month'],
            'company_holidays'      => CompanyHolidayResource::collection($this['company_holidays']),
            'preview'               => $this['preview'],
        ];
    }
}
