<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Department;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $departments = Department::all();
        $titles = [
            'Software Engineer',
            'Senior Software Engineer',
            'Tech Lead',
            'Product Manager',
            'UX Designer',
            'DevOps Engineer',
            'Data Scientist',
            'QA Engineer',
            'Security Engineer',
            'Full Stack Developer',
        ];

        $employees = [
            ['name' => 'Alice Johnson', 'email' => 'alice@example.com', 'title' => 'Tech Lead'],
            ['name' => 'Bob Smith', 'email' => 'bob@example.com', 'title' => 'Senior Software Engineer'],
            ['name' => 'Carol Williams', 'email' => 'carol@example.com', 'title' => 'Software Engineer'],
            ['name' => 'David Brown', 'email' => 'david@example.com', 'title' => 'DevOps Engineer'],
            ['name' => 'Eve Davis', 'email' => 'eve@example.com', 'title' => 'Data Scientist'],
            ['name' => 'Frank Miller', 'email' => 'frank@example.com', 'title' => 'QA Engineer'],
            ['name' => 'Grace Wilson', 'email' => 'grace@example.com', 'title' => 'Product Manager'],
            ['name' => 'Henry Taylor', 'email' => 'henry@example.com', 'title' => 'Full Stack Developer'],
            ['name' => 'Ivy Anderson', 'email' => 'ivy@example.com', 'title' => 'Security Engineer'],
            ['name' => 'Jack Thomas', 'email' => 'jack@example.com', 'title' => 'UX Designer'],
        ];

        foreach ($employees as $employee) {
            Employee::factory()->create([
                'department_id' => $departments->random()->id,
                'name' => $employee['name'],
                'email' => $employee['email'],
                'title' => $employee['title'],
            ]);
        }
    }
}
