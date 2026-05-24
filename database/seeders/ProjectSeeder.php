<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();

        $projects = [
            ['name' => 'API Platform Modernization', 'description' => 'Migrate legacy API to modern REST architecture'],
            ['name' => 'Customer Portal v2', 'description' => 'Complete redesign of customer-facing portal'],
            ['name' => 'Data Pipeline Optimization', 'description' => 'Improve ETL processes for analytics dashboard'],
            ['name' => 'Mobile App Launch', 'description' => 'iOS and Android app for customer engagement'],
            ['name' => 'Security Audit System', 'description' => 'Automated security compliance checking'],
            ['name' => 'Infrastructure Migration', 'description' => 'Move from on-premise to cloud infrastructure', 'paused' => true],
            ['name' => 'ML Model Deployment', 'description' => 'Deploy machine learning models to production'],
            ['name' => 'Real-time Notification Service', 'description' => 'WebSocket-based notification system', 'completed' => true],
        ];

        foreach ($projects as $data) {
            $factory = Project::factory();
            if ($data['paused']    ?? false) $factory = $factory->paused();
            if ($data['completed'] ?? false) $factory = $factory->completed();

            $project = $factory->create([
                'name'        => $data['name'],
                'description' => $data['description'],
            ]);

            $project->users()->attach($users->random(rand(2, 5))->pluck('id'));
        }
    }
}
