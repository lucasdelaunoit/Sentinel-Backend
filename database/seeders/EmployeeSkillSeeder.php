<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeSkillSeeder extends Seeder
{
    public function run(): void
    {
        $users  = User::all();
        $skills = Skill::all();

        foreach ($users as $user) {
            $userSkills = $skills->random(rand(3, 8));
            foreach ($userSkills as $skill) {
                $user->skills()->attach($skill->id, ['level' => rand(1, 5)]);
            }
        }
    }
}
