<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class EmployeeSkillSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::all();
        $skills = Skill::all();

        foreach ($employees as $employee) {
            $employeeSkills = $skills->random(rand(3, 8));
            foreach ($employeeSkills as $skill) {
                $employee->skills()->attach($skill->id, ['level' => rand(1, 5)]);
            }
        }
    }
}
