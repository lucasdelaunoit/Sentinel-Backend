<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'Engineering',
            'Product',
            'Design',
            'Operations',
            'DevOps',
            'Data Science',
            'Quality Assurance',
            'Security',
        ];

        foreach ($departments as $name) {
            Department::factory()->create(['name' => $name]);
        }
    }
}
