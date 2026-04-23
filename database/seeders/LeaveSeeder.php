<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Leave;
use Illuminate\Database\Seeder;

class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::all();
        $types = ['vacation', 'sick', 'personal', 'other'];

        foreach ($employees as $employee) {
            $numLeaves = rand(0, 3);
            for ($i = 0; $i < $numLeaves; $i++) {
                $start = now()->addDays(rand(1, 60));
                Leave::factory()->create([
                    'employee_id' => $employee->id,
                    'start_date' => $start,
                    'end_date' => $start->copy()->addDays(rand(1, 14)),
                    'type' => $types[array_rand($types)],
                ]);
            }
        }
    }
}
