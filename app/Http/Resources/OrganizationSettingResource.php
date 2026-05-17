<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationSettingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'industry'            => $this->industry,
            'size'                => $this->size,
            'location'            => $this->location,
            'methodology'         => $this->methodology,
            'team_structure'      => $this->team_structure,
            'risk_tolerance'      => $this->risk_tolerance,
            'working_days'        => $this->working_days,
            'timezone'            => $this->timezone,
            'standard_days_month' => $this->standard_days_month,
        ];
    }
}
