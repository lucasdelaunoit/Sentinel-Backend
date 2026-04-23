<?php

namespace Database\Seeders;

use App\Models\SkillCategory;
use Illuminate\Database\Seeder;

class SkillCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Programming Languages',
            'Frameworks',
            'Databases',
            'Cloud Platforms',
            'DevOps Tools',
            'Communication',
            'Leadership',
        ];

        foreach ($categories as $name) {
            SkillCategory::factory()->create(['name' => $name]);
        }
    }
}
