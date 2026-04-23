<?php

namespace Database\Factories;

use App\Models\Leave;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeaveFactory extends Factory
{
    protected $model = Leave::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 month', '+2 months');
        $duration = fake()->randomElement([1, 2, 3, 5, 7, 14, 21]);

        return [
            'employee_id' => Employee::factory(),
            'start_date' => $start,
            'end_date' => fake()->dateTimeInInterval($start, $duration . ' days'),
            'type' => fake()->randomElement(['vacation', 'vacation', 'sick', 'personal', 'other']),
            'reason' => fake()->optional()->sentence(),
        ];
    }
}
