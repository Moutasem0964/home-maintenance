<?php

namespace Database\Factories;

use App\Enums\UserRole;
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

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '09'.fake()->unique()->numerify('########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Client,
            'remember_token' => Str::random(10),
        ];
    }

    public function technicianRole(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::Technician]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => UserRole::Admin]);
    }
}
