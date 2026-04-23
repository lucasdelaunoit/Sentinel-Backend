<?php

namespace Database\Factories;

use App\Models\SkillCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class SkillCategoryFactory extends Factory
{
    protected $model = SkillCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Programming Languages',
                'Frameworks',
                'Databases',
                'Cloud Platforms',
                'DevOps Tools',
                'Communication',
                'Leadership',
            ]),
        ];
    }
}
