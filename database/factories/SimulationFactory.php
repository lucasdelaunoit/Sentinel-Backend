<?php

namespace Database\Factories;

use App\Models\Simulation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class SimulationFactory extends Factory
{
    protected $model = Simulation::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->randomElement([
                'Holiday Season Simulation',
                'Sick Leave Wave',
                'Team Member Departure',
                'Conference Week',
                'End of Quarter Rush',
            ]),
            'description' => fake()->sentence(),
            'result' => null,
        ];
    }
}
