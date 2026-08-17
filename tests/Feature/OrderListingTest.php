<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_sees_only_their_own_orders(): void
    {
        $client = User::factory()->verified()->create();
        $mine = Order::factory()->create(['client_id' => $client->id]);
        Order::factory()->create(); // someone else's

        $this->actingAs($client, 'sanctum')->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_technician_sees_only_assigned_orders(): void
    {
        $tech = Technician::factory()->active()->create();
        $techUser = $tech->user()->firstOrFail();

        $assigned = Order::factory()->create(['technician_id' => $tech->id]);
        Order::factory()->create(); // unassigned / not theirs

        $this->actingAs($techUser, 'sanctum')->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        $client = User::factory()->verified()->create();
        Order::factory()->create(['client_id' => $client->id, 'status' => OrderStatus::Pending]);
        Order::factory()->create(['client_id' => $client->id, 'status' => OrderStatus::Completed]);

        $this->actingAs($client, 'sanctum')->getJson('/api/orders?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'completed');
    }

    public function test_invalid_status_filter_is_rejected(): void
    {
        $client = User::factory()->verified()->create();

        $this->actingAs($client, 'sanctum')->getJson('/api/orders?status=not-a-status')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // ---------- show (role-aware) ----------

    public function test_the_client_can_show_their_own_order(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->create(['client_id' => $client->id]);

        $this->actingAs($client, 'sanctum')->getJson("/api/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.id', $order->id);
    }

    public function test_the_assigned_technician_can_show_the_order(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create(['technician_id' => $tech->id]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')->getJson("/api/orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.id', $order->id);
    }

    public function test_an_outsider_gets_404_on_show(): void
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/orders/{$order->id}")
            ->assertNotFound();
    }
}
