<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewing_an_order_updates_the_technician_rating_avg(): void
    {
        $client = User::factory()->verified()->create();
        $tech = Technician::factory()->active()->create();

        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::Completed,
        ]);

        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/review", [
            'cleanliness' => 4,
            'quality' => 5,
            'price_rating' => 3,
        ])->assertCreated();

        // (4 + 5 + 3) / 3 = 4.00
        $this->assertEquals(4.0, (float) $tech->refresh()->rating_avg);
    }
}
