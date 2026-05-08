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
            [
                'name'        => 'API Platform Modernization',
                'description' => 'Migrate legacy API to modern REST architecture',
                'status'      => 'active',
                'progress'    => 45,
            ],
            [
                'name'        => 'Customer Portal v2',
                'description' => 'Complete redesign of customer-facing portal',
                'status'      => 'active',
                'progress'    => 30,
            ],
            [
                'name'        => 'Data Pipeline Optimization',
                'description' => 'Improve ETL processes for analytics dashboard',
                'status'      => 'active',
                'progress'    => 70,
            ],
            [
                'name'        => 'Mobile App Launch',
                'description' => 'iOS and Android app for customer engagement',
                'status'      => 'active',
                'progress'    => 15,
            ],
            [
                'name'        => 'Security Audit System',
                'description' => 'Automated security compliance checking',
                'status'      => 'active',
                'progress'    => 60,
            ],
            [
                'name'        => 'Infrastructure Migration',
                'description' => 'Move from on-premise to cloud infrastructure',
                'status'      => 'on_hold',
                'progress'    => 25,
            ],
            [
                'name'        => 'ML Model Deployment',
                'description' => 'Deploy machine learning models to production',
                'status'      => 'active',
                'progress'    => 55,
            ],
            [
                'name'        => 'Real-time Notification Service',
                'description' => 'WebSocket-based notification system',
                'status'      => 'completed',
                'progress'    => 100,
            ],
        ];

        foreach ($projects as $projectData) {
            $project = Project::factory()->create($projectData);
            $project->users()->attach($users->random(rand(2, 5))->pluck('id'));
        }
    }
}
