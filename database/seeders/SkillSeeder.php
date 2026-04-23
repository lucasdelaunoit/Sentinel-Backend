<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\SkillCategory;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $php = SkillCategory::where('name', 'Programming Languages')->first()->id;
        $frameworks = SkillCategory::where('name', 'Frameworks')->first()->id;
        $databases = SkillCategory::where('name', 'Databases')->first()->id;
        $cloud = SkillCategory::where('name', 'Cloud Platforms')->first()->id;
        $devops = SkillCategory::where('name', 'DevOps Tools')->first()->id;
        $comm = SkillCategory::where('name', 'Communication')->first()->id;
        $lead = SkillCategory::where('name', 'Leadership')->first()->id;

        $skills = [
            ['skill_category_id' => $php, 'name' => 'PHP'],
            ['skill_category_id' => $php, 'name' => 'JavaScript'],
            ['skill_category_id' => $php, 'name' => 'TypeScript'],
            ['skill_category_id' => $php, 'name' => 'Python'],
            ['skill_category_id' => $php, 'name' => 'Go'],
            ['skill_category_id' => $php, 'name' => 'Rust'],
            ['skill_category_id' => $php, 'name' => 'Java'],
            ['skill_category_id' => $frameworks, 'name' => 'Laravel'],
            ['skill_category_id' => $frameworks, 'name' => 'React'],
            ['skill_category_id' => $frameworks, 'name' => 'Vue.js'],
            ['skill_category_id' => $frameworks, 'name' => 'Angular'],
            ['skill_category_id' => $frameworks, 'name' => 'Node.js'],
            ['skill_category_id' => $frameworks, 'name' => 'Django'],
            ['skill_category_id' => $frameworks, 'name' => 'Spring Boot'],
            ['skill_category_id' => $databases, 'name' => 'PostgreSQL'],
            ['skill_category_id' => $databases, 'name' => 'MySQL'],
            ['skill_category_id' => $databases, 'name' => 'MongoDB'],
            ['skill_category_id' => $databases, 'name' => 'Redis'],
            ['skill_category_id' => $cloud, 'name' => 'AWS'],
            ['skill_category_id' => $cloud, 'name' => 'Google Cloud'],
            ['skill_category_id' => $cloud, 'name' => 'Azure'],
            ['skill_category_id' => $devops, 'name' => 'Docker'],
            ['skill_category_id' => $devops, 'name' => 'Kubernetes'],
            ['skill_category_id' => $devops, 'name' => 'Terraform'],
            ['skill_category_id' => $devops, 'name' => 'Jenkins'],
            ['skill_category_id' => $devops, 'name' => 'GitHub Actions'],
            ['skill_category_id' => $comm, 'name' => 'Technical Writing'],
            ['skill_category_id' => $comm, 'name' => 'Public Speaking'],
            ['skill_category_id' => $lead, 'name' => 'Strategic Planning'],
            ['skill_category_id' => $lead, 'name' => 'Team Leadership'],
            ['skill_category_id' => $lead, 'name' => 'Mentoring'],
        ];

        foreach ($skills as $skill) {
            Skill::factory()->create($skill);
        }
    }
}
