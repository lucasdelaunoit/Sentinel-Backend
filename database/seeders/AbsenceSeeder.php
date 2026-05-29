<?php

namespace Database\Seeders;

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\User;
use Illuminate\Database\Seeder;

class AbsenceSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $types = AbsenceType::cases();

        foreach ($users as $user) {
            $numAbsences = rand(1, 4);
            for ($i = 0; $i < $numAbsences; $i++) {
                $offsetDays = rand(-30, 45);
                $start      = now()->addDays($offsetDays);
                Absence::factory()->create([
                    'user_id'    => $user->id,
                    'start_date' => $start,
                    'end_date'   => $start->copy()->addDays(rand(1, 10)),
                    'type'       => $types[array_rand($types)],
                ]);
            }
        }
    }
}
