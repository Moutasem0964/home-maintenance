<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'technician_id' => Technician::factory(),
            'type' => QuoteType::Initial,
            'labor_cost' => fake()->randomFloat(2, 20, 300),
            'warranty_days' => 0,
            'justification' => null,
            'status' => QuoteStatus::Pending,
            'expires_at' => now()->addHours(24),
        ];
    }
}
