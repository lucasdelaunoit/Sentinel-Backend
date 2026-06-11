<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Hand-crafted skill matrix. Levels are deliberate, not random:
 *  - Each squad has overlap on its core stack (redundancy → healthy projects).
 *  - Karim is the only Kubernetes/Terraform expert in the org → bus-factor-1 silo.
 *  - Thomas is the only React/TypeScript owner on the Mobile App team → absence risk.
 *  - Nobody knows Java or Spring Boot → org-wide skill gap for Data Pipeline.
 * Level scale: 1-2 = below most requirements, 3 = competent, 4-5 = expert.
 */
class EmployeeSkillSeeder extends Seeder
{
    /** email => [skill name => level] */
    private const MATRIX = [
        'lucasdelaunoit@qite.be' => [
            'PHP' => 5, 'Laravel' => 5, 'MySQL' => 4, 'React' => 3, 'Docker' => 3,
            'Team Leadership' => 4, 'Mentoring' => 4,
        ],
        'clint@qite.be' => [
            'PHP' => 4, 'Laravel' => 4, 'Strategic Planning' => 4, 'Team Leadership' => 5,
            'Mentoring' => 5, 'Public Speaking' => 3,
        ],
        'amira.haddad@qite.be' => [
            'PHP' => 4, 'Laravel' => 4, 'PostgreSQL' => 4, 'Redis' => 3, 'Docker' => 3,
        ],
        'jonas.peeters@qite.be' => [
            'PHP' => 3, 'Laravel' => 3, 'MySQL' => 3, 'Node.js' => 2, 'JavaScript' => 2,
        ],
        'emma.claes@qite.be' => [
            'PHP' => 2, 'Laravel' => 2, 'MySQL' => 2, 'JavaScript' => 2,
        ],
        'sofia.moreau@qite.be' => [
            'JavaScript' => 5, 'TypeScript' => 4, 'React' => 5, 'Node.js' => 3, 'Technical Writing' => 3,
        ],
        'thomas.janssens@qite.be' => [
            'JavaScript' => 4, 'TypeScript' => 3, 'React' => 4, 'Vue.js' => 3,
        ],
        'lien.desmet@qite.be' => [
            'JavaScript' => 3, 'TypeScript' => 2, 'React' => 2, 'Vue.js' => 2,
        ],
        'karim.benali@qite.be' => [
            'Docker' => 5, 'Kubernetes' => 4, 'Terraform' => 4, 'AWS' => 4, 'GitHub Actions' => 4, 'Go' => 3,
        ],
        'petra.novak@qite.be' => [
            'Docker' => 4, 'AWS' => 3, 'GitHub Actions' => 3, 'Jenkins' => 3, 'Kubernetes' => 2, 'Terraform' => 2,
        ],
        'rajesh.iyer@qite.be' => [
            'Python' => 5, 'PostgreSQL' => 4, 'MongoDB' => 3, 'Redis' => 2, 'Docker' => 2,
        ],
        'hannah.vermeulen@qite.be' => [
            'Python' => 4, 'MongoDB' => 2, 'Technical Writing' => 2, 'Public Speaking' => 2,
        ],
        'diego.fernandez@qite.be' => [
            'Python' => 4, 'MongoDB' => 3, 'Docker' => 3, 'Go' => 2,
        ],
        'nora.elamrani@qite.be' => [
            'Python' => 3, 'Docker' => 3, 'AWS' => 3, 'PHP' => 2,
        ],
        'bram.wouters@qite.be' => [
            'JavaScript' => 3, 'Python' => 2, 'GitHub Actions' => 2, 'Technical Writing' => 2,
        ],
        'julie.lambert@qite.be' => [
            'Strategic Planning' => 3, 'Public Speaking' => 4, 'Technical Writing' => 4, 'Team Leadership' => 3,
        ],
    ];

    public function run(): void
    {
        $skillIds = Skill::all()->keyBy('name');

        foreach (self::MATRIX as $email => $skills) {
            $user = User::where('email', $email)->firstOrFail();

            $sync = [];
            foreach ($skills as $name => $level) {
                $sync[$skillIds[$name]->id] = ['level' => $level];
            }
            $user->skills()->sync($sync);
        }
    }
}
