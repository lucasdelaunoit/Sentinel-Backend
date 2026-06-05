<?php

namespace Database\Factories;

use App\Enums\AbsenceHalf;
use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AbsenceFactory extends Factory
{
    protected $model = Absence::class;

    public function definition(): array
    {
        $start    = fake()->dateTimeBetween('-1 month', '+2 months');
        $duration = fake()->randomElement([1, 2, 3, 5, 7, 14, 21]);

        return [
            'user_id'    => User::factory(),
            'start_date' => $start,
            'start_half' => AbsenceHalf::Morning,
            'end_date'   => fake()->dateTimeInInterval($start, $duration . ' days'),
            'end_half'   => AbsenceHalf::Afternoon,
            'type' => fake()->randomElement([
                AbsenceType::Vacation,
                AbsenceType::Vacation,
                AbsenceType::Conference,
                AbsenceType::Training,
                AbsenceType::Parental,
                AbsenceType::Other,
            ]),
            'reason'     => fake()->optional()->sentence(),
        ];
    }
}
