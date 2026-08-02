<?php

namespace Database\Factories;

use App\Enums\TechnicianStatus;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Technician> */
class TechnicianFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->technicianRole(),
            'status' => TechnicianStatus::Pending,
            'is_available' => false,
            'charter_accepted_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => TechnicianStatus::Active]);
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TechnicianStatus::Active,
            'is_available' => true,
            'current_lat' => 33.5,
            'current_lng' => 36.3,
        ]);
    }
}
