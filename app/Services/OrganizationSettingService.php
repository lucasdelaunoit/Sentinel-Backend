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
                'name'                          => 'Sentinel',
                'risk_tolerance'                => 'balanced',
                'working_days'                  => [1, 1, 1, 1, 1, 0, 0],
                'risk_weight_bus_factor'        => 35,
                'risk_weight_uncovered_skills'  => 30,
                'risk_weight_silos'             => 20,
                'risk_weight_absence_impact'    => 15,
                'silo_threshold'                => 1,
                'kci_min_level'                 => 3,
                'critical_bus_factor_threshold' => 2,
                'health_risk_weight'            => 70,
                'absence_horizon_days'          => 14,
                'rule_violation_penalty'        => 15,
            ],
        );
    }

    /**
     * <summary>
     *  Update identity + risk/threshold config fields on the singleton settings row.
     *  Calendar fields (working_days) are not touched by this method.
     * </summary>
     *
     * @param array $data Validated payload
     * @return OrganizationSetting Freshly reloaded settings row
     */
    public function updateOrganizationSetting(array $data): OrganizationSetting
    {
        $setting = $this->getOrganizationSetting();

        $setting->update(array_intersect_key($data, array_flip([
            'name',
            'risk_tolerance',
            'risk_weight_bus_factor',
            'risk_weight_uncovered_skills',
            'risk_weight_silos',
            'risk_weight_absence_impact',
            'silo_threshold',
            'kci_min_level',
            'critical_bus_factor_threshold',
            'health_risk_weight',
            'absence_horizon_days',
            'rule_violation_penalty',
        ])));

        return $setting->fresh();
    }

    /**
     * <summary>
     *  Update calendar fields (working_days) on the singleton settings row.
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
        ])));

        return $setting->fresh();
    }
}
