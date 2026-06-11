<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Skill;
use Illuminate\Database\Seeder;

/**
 * Requirements are tuned against the EmployeeSkillSeeder matrix + ProjectSeeder rosters so
 * each project lands in a known coverage state (silo_threshold = 1: 1 owner = siloed,
 * ≥2 = safe, 0 = uncovered). The expected matrix per project is noted inline — if you
 * change a level here or in the skill matrix, re-check the story it breaks.
 */
class ProjectSkillReqSeeder extends Seeder
{
    /** project name => [skill name => required level] */
    private const REQUIREMENTS = [
        // All safe (≥2 owners each): PHP (Lucas 5, Jonas 3) · Laravel (Lucas, Jonas) ·
        // React (Sofia 5, Thomas 4, Lucas 3) · MySQL (Lucas 4, Jonas 3) · JS (Sofia, Thomas).
        'Customer Portal v2' => [
            'PHP' => 3, 'Laravel' => 3, 'React' => 3, 'MySQL' => 3, 'JavaScript' => 3,
        ],
        // PHP/Laravel safe (Amira, Lucas, Jonas) — PostgreSQL + Redis siloed on Amira → bus 1.
        'API Platform Modernization' => [
            'PHP' => 3, 'Laravel' => 3, 'PostgreSQL' => 3, 'Redis' => 3,
        ],
        // Docker (Karim 5, Petra 4) + AWS (Karim, Petra, Nora) safe — Kubernetes + Terraform
        // siloed on Karim (Petra is level 2) → bus 1, Karim = org-critical.
        'Infrastructure Migration' => [
            'Kubernetes' => 4, 'Terraform' => 3, 'Docker' => 4, 'AWS' => 3,
        ],
        // JS safe (Thomas 4, Lien 3, Bram 3) — React + TypeScript siloed on Thomas,
        // Public Speaking siloed on Julie → bus 1, and Thomas is away next week.
        'Mobile App Launch' => [
            'React' => 3, 'TypeScript' => 3, 'JavaScript' => 3, 'Public Speaking' => 3,
        ],
        // Python safe (Rajesh 5, Hannah 4, Diego 4) — PostgreSQL siloed on Rajesh —
        // Java uncovered org-wide → bus 0, coverage 67%, deliberate hiring gap.
        'Data Pipeline Optimization' => [
            'Python' => 4, 'PostgreSQL' => 4, 'Java' => 3,
        ],
        // Docker safe (Nora 3, Karim 5) — Python siloed on Nora (Bram is 2),
        // GitHub Actions siloed on Karim (Bram is 2) → bus 1.
        'Security Audit System' => [
            'Python' => 3, 'Docker' => 3, 'GitHub Actions' => 3,
        ],
        // The disaster: Java + Spring Boot + Angular uncovered org-wide (contractors left),
        // PHP + MySQL siloed on Jonas (Emma is level 2) — and Jonas starts parental leave
        // next week → bus 0, coverage 40%, absence impact stacks on top → red fragility.
        'Legacy ERP Replacement' => [
            'Java' => 4, 'Spring Boot' => 3, 'Angular' => 3, 'PHP' => 3, 'MySQL' => 3,
        ],
        // Python safe (Diego 4, Hannah 4) — MongoDB siloed on Diego, Kubernetes siloed on Karim.
        'ML Model Deployment' => [
            'Python' => 4, 'MongoDB' => 3, 'Kubernetes' => 3,
        ],
        // Completed — historical only.
        'Real-time Notification Service' => [
            'Node.js' => 3, 'JavaScript' => 3,
        ],
        // Paused — excluded from active metrics.
        'Analytics Dashboard' => [
            'Python' => 3, 'React' => 3, 'PostgreSQL' => 3,
        ],
    ];

    public function run(): void
    {
        $skills = Skill::all()->keyBy('name');

        foreach (self::REQUIREMENTS as $projectName => $requirements) {
            $project = Project::where('name', $projectName)->firstOrFail();

            $sync = [];
            foreach ($requirements as $skillName => $level) {
                $sync[$skills[$skillName]->id] = ['required_level' => $level];
            }
            $project->skillRequirements()->sync($sync);
        }
    }
}
