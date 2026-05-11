<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['firstname' => 'Clint',  'lastname' => '',          'email' => 'clint@qite.be'],
            ['firstname' => 'Lucas',  'lastname' => 'Delaunoit', 'email' => 'lucasdelaunoit@qite.be'],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'firstname' => $data['firstname'],
                    'lastname'  => $data['lastname'],
                    'password'  => Hash::make('password'),
                ]
            );
        }
    }
}
