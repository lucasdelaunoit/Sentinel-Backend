<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'title' => fake()->randomElement([
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
            ]),
            'is_remote' => fake()->boolean(30),
        ];
    }
}
