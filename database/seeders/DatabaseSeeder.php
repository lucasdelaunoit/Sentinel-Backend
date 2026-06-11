<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OrganizationSettingSeeder::class,
            UserSeeder::class,
            SkillCategorySeeder::class,
            SkillSeeder::class,
            DepartmentSeeder::class,
            EmployeeSeeder::class,
            ProjectSeeder::class,
            EmployeeSkillSeeder::class,
            ProjectSkillReqSeeder::class,
            AbsenceSeeder::class,
        ]);

        Artisan::call('sentinel:recalc-everything');
        $this->command?->info(Artisan::output());
    }
}
