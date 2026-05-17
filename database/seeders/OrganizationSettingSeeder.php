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
                'name'                => 'QITE',
                'industry'            => 'Technology',
                'size'                => '11-50',
                'location'            => 'Belgium',
                'methodology'         => 'agile',
                'team_structure'      => 'cross-functional',
                'risk_tolerance'      => 'balanced',
                'working_days'        => [1, 1, 1, 1, 1, 0, 0],
                'timezone'            => 'Europe/Brussels',
                'standard_days_month' => 22,
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
