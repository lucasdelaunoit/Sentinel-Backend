<?php

namespace Database\Factories;

use App\Models\Skill;
use App\Models\SkillCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class SkillFactory extends Factory
{
    protected $model = Skill::class;

    public function definition(): array
    {
        return [
            'skill_category_id' => SkillCategory::factory(),
            'name' => fake()->unique()->randomElement([
                'PHP',
                'JavaScript',
                'TypeScript',
                'Python',
                'Go',
                'Rust',
                'Java',
                'C#',
                'Ruby',
                'Laravel',
                'React',
                'Vue.js',
                'Angular',
                'Node.js',
                'Django',
                'FastAPI',
                'Spring Boot',
                'PostgreSQL',
                'MySQL',
                'MongoDB',
                'Redis',
                'Elasticsearch',
                'AWS',
                'Google Cloud',
                'Azure',
                'Docker',
                'Kubernetes',
                'Terraform',
                'Jenkins',
                'GitLab CI',
                'GitHub Actions',
                'Slack',
                'Confluence',
                'Strategic Planning',
                'Team Leadership',
                'Mentoring',
            ]),
        ];
    }
}
