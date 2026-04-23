<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SkillCategorySeeder::class,
            SkillSeeder::class,
            DepartmentSeeder::class,
            EmployeeSeeder::class,
            ProjectSeeder::class,
            EmployeeSkillSeeder::class,
            ProjectSkillReqSeeder::class,
            LeaveSeeder::class,
        ]);
    }
}
