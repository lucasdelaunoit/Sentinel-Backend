<?php

namespace Database\Seeders;

use App\Models\Leave;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeaveSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $types = ['vacation', 'sick', 'personal', 'other'];

        foreach ($users as $user) {
            $numLeaves = rand(1, 4);
            for ($i = 0; $i < $numLeaves; $i++) {
                $offsetDays = rand(-30, 45);
                $start      = now()->addDays($offsetDays);
                Leave::factory()->create([
                    'user_id'    => $user->id,
                    'start_date' => $start,
                    'end_date'   => $start->copy()->addDays(rand(1, 10)),
                    'type'       => $types[array_rand($types)],
                ]);
            }
        }
    }
}
