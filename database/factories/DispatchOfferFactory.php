<?php

namespace Database\Factories;

use App\Enums\DispatchOfferStatus;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DispatchOffer> */
class DispatchOfferFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'technician_id' => Technician::factory(),
            'status' => DispatchOfferStatus::Offered,
            'offered_at' => now(),
            'expires_at' => now()->addMinutes(2),
        ];
    }
}
