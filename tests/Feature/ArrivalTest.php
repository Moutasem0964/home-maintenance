<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrivalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    /** @return array{0: Order, 1: Technician} accepted order at a known location + its tech. */
    private function acceptedOrderAt(float $lat, float $lng): array
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::Accepted,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        return [$order, $tech];
    }

    public function test_arrive_requires_authentication(): void
    {
        [$order] = $this->acceptedOrderAt(33.5, 36.3);

        $this->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.5, 'lng' => 36.3])->assertUnauthorized();
    }

    public function test_only_the_assigned_technician_can_mark_arrival(): void
    {
        [$order] = $this->acceptedOrderAt(33.5, 36.3);
        $other = Technician::factory()->active()->create();

        $this->actingAs($other->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.5, 'lng' => 36.3])->assertForbidden();
    }

    public function test_technician_within_range_marks_arrival(): void
    {
        [$order, $tech] = $this->acceptedOrderAt(33.5, 36.3);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.5, 'lng' => 36.3])
            ->assertOk()->assertJsonPath('data.id', $order->id);

        $this->assertNotNull($order->refresh()->arrived_at);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'arrived']);
    }

    public function test_arrival_outside_the_radius_is_rejected(): void
    {
        [$order, $tech] = $this->acceptedOrderAt(33.5, 36.3);

        // ~1.4 km north of the order — well beyond the 50 m radius.
        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.513, 'lng' => 36.3])
            ->assertStatus(422);

        $this->assertNull($order->refresh()->arrived_at);
    }

    public function test_cannot_mark_arrival_twice(): void
    {
        [$order, $tech] = $this->acceptedOrderAt(33.5, 36.3);
        $techUser = $tech->user()->firstOrFail();

        $this->actingAs($techUser, 'sanctum')
            ->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.5, 'lng' => 36.3])->assertOk();

        $this->actingAs($techUser, 'sanctum')
            ->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.5, 'lng' => 36.3])->assertStatus(409);
    }

    public function test_cannot_mark_arrival_when_not_accepted(): void
    {
        [$order, $tech] = $this->acceptedOrderAt(33.5, 36.3);
        $order->update(['status' => OrderStatus::InProgress]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/arrive", ['lat' => 33.5, 'lng' => 36.3])->assertStatus(409);
    }
}
