<?php

namespace Database\Seeders;

use App\Models\CompanyHoliday;
use App\Models\OrganizationSetting;
use Illuminate\Database\Seeder;

class OrganizationSettingSeeder extends Seeder
{
    public function run(): void
    {
        OrganizationSetting::updateOrCreate(
            ['id' => 1],
            [
                'name'                          => 'QITE',
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

        // Belgian public holidays — seeded as recurring so they apply every year.
        $holidays = [
            ['name' => "New Year's Day",      'date' => '2026-01-01'],
            ['name' => 'Labour Day',          'date' => '2026-05-01'],
            ['name' => 'Belgian National Day','date' => '2026-07-21'],
            ['name' => 'Assumption Day',      'date' => '2026-08-15'],
            ['name' => 'All Saints Day',      'date' => '2026-11-01'],
            ['name' => 'Armistice Day',       'date' => '2026-11-11'],
            ['name' => 'Christmas Day',       'date' => '2026-12-25'],
        ];

        foreach ($holidays as $h) {
            CompanyHoliday::updateOrCreate(
                ['date' => $h['date'], 'name' => $h['name']],
                ['recurring' => true],
            );
        }
    }
}
