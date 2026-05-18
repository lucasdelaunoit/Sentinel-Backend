<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-6 months', 'now');
        $deadline  = fake()->dateTimeBetween($startedAt, '+6 months');

        return [
            'name'         => fake()->randomElement([
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
            'description'  => fake()->sentence(),
            'fragility_raw'  => 0,
            'bus_factor'     => 0,
            'trajectory_raw' => 100,
            'started_at'   => $startedAt,
            'deadline'     => $deadline,
            'paused_at'    => null,
            'completed_at' => null,
            'archived_at'  => null,
        ];
    }

    public function planned(): self
    {
        return $this->state(fn() => [
            'started_at' => fake()->dateTimeBetween('+1 day', '+2 months'),
        ]);
    }

    public function paused(): self
    {
        return $this->state(fn() => ['paused_at' => now()]);
    }

    public function completed(): self
    {
        return $this->state(fn() => ['completed_at' => now()]);
    }

    public function archived(): self
    {
        return $this->state(fn() => ['archived_at' => now()]);
    }
}
