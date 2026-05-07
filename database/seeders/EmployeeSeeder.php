<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Department;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    private const TITLES = [
        'Software Engineer',
        'Senior Software Engineer',
        'Staff Engineer',
        'Principal Engineer',
        'Tech Lead',
        'Engineering Manager',
        'Full Stack Developer',
        'Backend Developer',
        'Frontend Developer',
        'DevOps Engineer',
        'Site Reliability Engineer',
        'Platform Engineer',
        'Data Scientist',
        'Data Engineer',
        'ML Engineer',
        'QA Engineer',
        'Security Engineer',
        'Product Manager',
        'UX Designer',
        'UX Researcher',
    ];

    public function run(): void
    {
        $departments = Department::all();
        $count = rand(6, 30);

        Employee::factory($count)->create([
            'department_id' => fn() => $departments->random()->id,
            'title' => fn() => self::TITLES[array_rand(self::TITLES)],
        ]);
    }
}
