<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
class AddressFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Home', 'Work', 'Other']),
            'lat' => fake()->latitude(),
            'lng' => fake()->longitude(),
            'building_no' => (string) fake()->numberBetween(1, 200),
            'floor' => (string) fake()->numberBetween(0, 20),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
