<?php

namespace Database\Factories;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_id' => User::factory(),
            'service_category_id' => ServiceCategory::factory(),
            'address_id' => null,
            'lat' => fake()->latitude(),
            'lng' => fake()->longitude(),
            'description' => fake()->optional()->sentence(),
            'kind' => OrderKind::Normal,
            'type' => OrderType::Urgent,
            'scheduled_at' => null,
            'status' => OrderStatus::Pending,
            'commission_rate' => '0.1000',
            'commission_amount' => '0',
            'inspection_fee' => '50.00',
        ];
    }
}
