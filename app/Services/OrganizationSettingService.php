<?php

namespace App\Services;

use App\Models\OrganizationSetting;

class OrganizationSettingService
{
    /**
     * <summary>
     *  Return the singleton OrganizationSetting row, creating it with defaults if missing.
     * </summary>
     *
     * @return OrganizationSetting Singleton settings row
     */
    public function getOrganizationSetting(): OrganizationSetting
    {
        return OrganizationSetting::firstOrCreate(
            ['id' => 1],
            [
                'name'                => 'Sentinel',
                'methodology'         => 'agile',
                'team_structure'      => 'cross-functional',
                'risk_tolerance'      => 'balanced',
                'working_days'        => [1, 1, 1, 1, 1, 0, 0],
                'standard_days_month' => 22,
            ],
        );
    }

    /**
     * <summary>
     *  Update identity + operational profile fields on the singleton settings row.
     *  Calendar fields (working_days, timezone) are not touched by this method.
     * </summary>
     *
     * @param array $data Validated payload — identity + operational fields
     * @return OrganizationSetting Freshly reloaded settings row
     */
    public function updateOrganizationSetting(array $data): OrganizationSetting
    {
        $setting = $this->getOrganizationSetting();

        $setting->update(array_intersect_key($data, array_flip([
            'name',
            'industry',
            'size',
            'location',
            'methodology',
            'team_structure',
            'risk_tolerance',
        ])));

        return $setting->fresh();
    }

    /**
     * <summary>
     *  Update calendar fields (working_days, timezone, standard_days_month) on the singleton settings row.
     * </summary>
     *
     * @param array $data Validated calendar payload
     * @return OrganizationSetting Freshly reloaded settings row
     */
    public function updateCalendarSetting(array $data): OrganizationSetting
    {
        $setting = $this->getOrganizationSetting();

        $setting->update(array_intersect_key($data, array_flip([
            'working_days',
            'timezone',
            'standard_days_month',
        ])));

        return $setting->fresh();
    }
}
