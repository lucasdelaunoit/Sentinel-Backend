<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Named, deterministic roster — 14 fictional employees plus department/title enrichment
 * for the two real login users seeded by UserSeeder. Every profile is hand-crafted so the
 * skill matrix (EmployeeSkillSeeder) and project staffing (ProjectSeeder) tell a coherent
 * story: squads with overlapping skills, one irreplaceable DevOps lead, one understaffed
 * data team. No randomness — re-seeding always produces the same org.
 */
class EmployeeSeeder extends Seeder
{
    /** email => [firstname, lastname, department, title] */
    public const ROSTER = [
        // Real logins (created by UserSeeder, enriched here)
        'clint@qite.be'              => ['Clint',  '',           'Engineering',      'Engineering Manager'],
        'lucasdelaunoit@qite.be'     => ['Lucas',  'Delaunoit',  'Engineering',      'Tech Lead'],
        // Backend squad
        'amira.haddad@qite.be'       => ['Amira',  'Haddad',     'Engineering',      'Senior Backend Developer'],
        'jonas.peeters@qite.be'      => ['Jonas',  'Peeters',    'Engineering',      'Backend Developer'],
        'emma.claes@qite.be'         => ['Emma',   'Claes',      'Engineering',      'Junior Backend Developer'],
        // Frontend squad
        'sofia.moreau@qite.be'       => ['Sofia',  'Moreau',     'Engineering',      'Senior Frontend Developer'],
        'thomas.janssens@qite.be'    => ['Thomas', 'Janssens',   'Engineering',      'Frontend Developer'],
        'lien.desmet@qite.be'        => ['Lien',   'De Smet',    'Engineering',      'Frontend Developer'],
        // DevOps / Infra
        'karim.benali@qite.be'       => ['Karim',  'Benali',     'DevOps',           'DevOps Lead'],
        'petra.novak@qite.be'        => ['Petra',  'Novak',      'DevOps',           'Site Reliability Engineer'],
        // Data
        'rajesh.iyer@qite.be'        => ['Rajesh', 'Iyer',       'Data Science',     'Data Engineer'],
        'hannah.vermeulen@qite.be'   => ['Hannah', 'Vermeulen',  'Data Science',     'Data Scientist'],
        'diego.fernandez@qite.be'    => ['Diego',  'Fernández',  'Data Science',     'ML Engineer'],
        // Security / QA / Product
        'nora.elamrani@qite.be'      => ['Nora',   'El Amrani',  'Security',         'Security Engineer'],
        'bram.wouters@qite.be'       => ['Bram',   'Wouters',    'Quality Assurance', 'QA Engineer'],
        'julie.lambert@qite.be'      => ['Julie',  'Lambert',    'Product',          'Product Manager'],
    ];

    public function run(): void
    {
        $departments = Department::all()->keyBy('name');

        foreach (self::ROSTER as $email => [$firstname, $lastname, $department, $title]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'firstname'     => $firstname,
                    'lastname'      => $lastname,
                    'department_id' => $departments[$department]->id,
                    'title'         => $title,
                    'password'      => Hash::make('password'),
                ],
            );
        }
    }
}
