<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'firstname'          => fake()->firstName(),
            'lastname'           => fake()->lastName(),
            'email'              => fake()->unique()->safeEmail(),
            'email_verified_at'  => now(),
            'password'           => static::$password ??= Hash::make('password'),
            'remember_token'     => Str::random(10),
            'department_id'      => Department::factory(),
            'title'              => fake()->randomElement([
                'Software Engineer',
                'Senior Software Engineer',
                'Tech Lead',
                'Product Manager',
                'UX Designer',
                'DevOps Engineer',
                'Data Scientist',
                'QA Engineer',
                'Security Engineer',
                'Full Stack Developer',
            ]),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn(array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
