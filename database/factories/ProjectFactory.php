<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'API Platform Modernization',
                'Customer Portal v2',
                'Data Pipeline Optimization',
                'Mobile App Launch',
                'Security Audit System',
                'Analytics Dashboard',
                'Infrastructure Migration',
                'ML Model Deployment',
                'Real-time Notification Service',
                'Legacy System Replacement',
            ]),
            'description' => fake()->sentence(),
            'status' => fake()->randomElement(['active', 'active', 'active', 'on_hold', 'completed']),
            'progress' => fake()->numberBetween(0, 100),
            'risk_score' => 0,
            'bus_factor' => 0,
            'health' => 100,
            'started_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'ended_at' => fake()->optional()->dateTimeBetween('now', '+6 months'),
        ];
    }
}
