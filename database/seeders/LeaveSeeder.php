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
            $numLeaves = rand(1, 4);
            for ($i = 0; $i < $numLeaves; $i++) {
                // Mix past, current, and future leaves so dashboard always shows active ones
                $offsetDays = rand(-30, 45);
                $start = now()->addDays($offsetDays);
                Leave::factory()->create([
                    'employee_id' => $employee->id,
                    'start_date' => $start,
                    'end_date' => $start->copy()->addDays(rand(1, 10)),
                    'type' => $types[array_rand($types)],
                ]);
            }
        }
    }
}
