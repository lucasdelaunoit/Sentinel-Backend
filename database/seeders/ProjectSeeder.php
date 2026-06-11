<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Deterministic project portfolio — each project is staffed to showcase one risk scenario
 * (requirements live in ProjectSkillReqSeeder, same keys):
 *
 *  Customer Portal v2          healthy — every skill has ≥2 owners, bus factor 2.
 *  API Platform Modernization  moderate — Amira solely owns PostgreSQL + Redis (bus 1).
 *  Infrastructure Migration    classic silo — Karim solely owns Kubernetes + Terraform.
 *  Mobile App Launch           absence risk — Thomas solely owns React + TS, and he is
 *                              on leave next week (AbsenceSeeder) → upcoming risk event.
 *  Data Pipeline Optimization  skill gap — requires Java that nobody in the org has
 *                              (bus 0, partial coverage, "Already at risk").
 *  Security Audit System       thin — every skill siloed on Nora or Karim (bus 1).
 *  Legacy ERP Replacement      disaster — inherited Java monolith, the contractors who
 *                              built it are gone: 3 of 5 required skills uncovered
 *                              org-wide, the rest siloed on Jonas who is about to leave
 *                              on parental leave (bus 0, red fragility).
 *  ML Model Deployment         planned — starts in ~3 weeks, silos already visible.
 *  Real-time Notification Service  completed — historical record.
 *  Analytics Dashboard         paused — excluded from active metrics.
 */
class ProjectSeeder extends Seeder
{
    /** name => [description, team emails, status] */
    public const PORTFOLIO = [
        'Customer Portal v2' => [
            'description' => 'Complete redesign of the customer-facing portal',
            'team'        => ['lucasdelaunoit@qite.be', 'sofia.moreau@qite.be', 'thomas.janssens@qite.be', 'jonas.peeters@qite.be', 'emma.claes@qite.be'],
            'status'      => 'active',
        ],
        'API Platform Modernization' => [
            'description' => 'Migrate the legacy API to a modern REST architecture',
            'team'        => ['amira.haddad@qite.be', 'lucasdelaunoit@qite.be', 'jonas.peeters@qite.be', 'emma.claes@qite.be'],
            'status'      => 'active',
        ],
        'Infrastructure Migration' => [
            'description' => 'Move from on-premise to cloud infrastructure',
            'team'        => ['karim.benali@qite.be', 'petra.novak@qite.be', 'nora.elamrani@qite.be'],
            'status'      => 'active',
        ],
        'Mobile App Launch' => [
            'description' => 'iOS and Android app for customer engagement',
            'team'        => ['thomas.janssens@qite.be', 'lien.desmet@qite.be', 'bram.wouters@qite.be', 'julie.lambert@qite.be'],
            'status'      => 'active',
        ],
        'Data Pipeline Optimization' => [
            'description' => 'Improve ETL processes feeding the analytics stack',
            'team'        => ['rajesh.iyer@qite.be', 'hannah.vermeulen@qite.be', 'diego.fernandez@qite.be'],
            'status'      => 'active',
        ],
        'Security Audit System' => [
            'description' => 'Automated security compliance checking',
            'team'        => ['nora.elamrani@qite.be', 'karim.benali@qite.be', 'bram.wouters@qite.be'],
            'status'      => 'active',
        ],
        'Legacy ERP Replacement' => [
            'description' => 'Replace the inherited Java ERP monolith built by external contractors',
            'team'        => ['jonas.peeters@qite.be', 'emma.claes@qite.be'],
            'status'      => 'active',
        ],
        'ML Model Deployment' => [
            'description' => 'Deploy machine learning models to production',
            'team'        => ['diego.fernandez@qite.be', 'hannah.vermeulen@qite.be', 'karim.benali@qite.be'],
            'status'      => 'planned',
        ],
        'Real-time Notification Service' => [
            'description' => 'WebSocket-based notification system',
            'team'        => ['sofia.moreau@qite.be', 'jonas.peeters@qite.be', 'lucasdelaunoit@qite.be'],
            'status'      => 'completed',
        ],
        'Analytics Dashboard' => [
            'description' => 'Self-service BI dashboard for internal teams',
            'team'        => ['rajesh.iyer@qite.be', 'sofia.moreau@qite.be'],
            'status'      => 'paused',
        ],
    ];

    public function run(): void
    {
        $users = User::all()->keyBy('email');

        foreach (self::PORTFOLIO as $name => $data) {
            $factory = Project::factory();
            $factory = match ($data['status']) {
                'planned'   => $factory->planned(),
                'paused'    => $factory->paused(),
                'completed' => $factory->completed(),
                default     => $factory,
            };

            $project = $factory->create([
                'name'        => $name,
                'description' => $data['description'],
            ]);

            $project->users()->sync(
                collect($data['team'])->map(fn(string $email) => $users[$email]->id)->all(),
            );
        }
    }
}
