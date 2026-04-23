<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Skill;
use Illuminate\Database\Seeder;

class ProjectSkillReqSeeder extends Seeder
{
    public function run(): void
    {
        $projects = Project::all();
        $skills = Skill::all();

        foreach ($projects as $project) {
            $projectSkills = $skills->random(rand(2, 5));
            foreach ($projectSkills as $skill) {
                $project->skillRequirements()->attach($skill->id, ['required_level' => rand(2, 4)]);
            }
        }
    }
}
