<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    /** A verified client whose wallet has been funded with the given balance. */
    private function fundedClient(string $balance = '100.00'): User
    {
        $user = User::factory()->verified()->create();
        Wallet::create(['user_id' => $user->id]);
        app(WalletService::class)->topUp($user, $balance, 'seed-'.$user->id);

        return $user->refresh();
    }

    // ---------- Authentication ----------

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/orders', [])->assertUnauthorized();
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    // ---------- Happy paths ----------

    public function test_creates_urgent_order_and_holds_inspection_fee(): void
    {
        $user = $this->fundedClient('100.00');
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
            'description' => 'Leaking sink',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'urgent');

        $this->assertDatabaseHas('orders', [
            'client_id' => $user->id,
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'status' => 'pending',
            'inspection_fee' => '50.00',
        ]);

        $wallet = $user->wallet()->firstOrFail();
        $this->assertSame(50.0, (float) $wallet->available_balance, 'available should drop by the fee');
        $this->assertSame(50.0, (float) $wallet->held_balance, 'held should rise by the fee');

        $this->assertDatabaseHas('payments', [
            'payer_id' => $user->id,
            'type' => 'inspection',
            'status' => 'held',
            'amount' => '50.00',
        ]);
    }

    public function test_creates_scheduled_order_with_future_datetime(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'scheduled');

        $this->assertDatabaseHas('orders', [
            'client_id' => $user->id,
            'type' => 'scheduled',
            'status' => 'pending',
        ]);
    }

    // ---------- Money integrity ----------

    public function test_insufficient_balance_is_rejected_and_creates_no_order(): void
    {
        $user = $this->fundedClient('10.00'); // below the 50 fee
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);

        $wallet = $user->wallet()->firstOrFail();
        $this->assertSame(10.0, (float) $wallet->available_balance, 'balance must be untouched on rollback');
        $this->assertSame(0.0, (float) $wallet->held_balance);
    }

    // ---------- Snapshots ----------

    public function test_snapshots_lat_lng_from_the_address(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create(['lat' => 33.5138, 'lng' => 36.2765]);

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertCreated();

        $order = $user->orders()->firstOrFail();
        $this->assertSame($address->fresh()->lat, $order->lat);
        $this->assertSame($address->fresh()->lng, $order->lng);
    }

    public function test_snapshots_commission_rate_and_inspection_fee(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertCreated();

        $order = $user->orders()->firstOrFail();
        $this->assertSame('0.1000', $order->commission_rate);
        $this->assertSame('50.00', $order->inspection_fee);
        $this->assertSame('0.00', $order->commission_amount);
    }

    public function test_ignores_spoofed_server_owned_fields(): void
    {
        $user = $this->fundedClient();
        $other = User::factory()->create();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
            'client_id' => $other->id,   // spoof
            'status' => 'completed',     // spoof
            'inspection_fee' => '0.01',  // spoof
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame($user->id, $order->client_id);
        $this->assertSame('pending', $order->status->value);
        $this->assertSame('50.00', $order->inspection_fee);
    }

    // ---------- Validation ----------

    public function test_rejects_missing_required_fields(): void
    {
        $user = $this->fundedClient();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_category_id', 'address_id', 'type']);
    }

    public function test_rejects_invalid_type(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'whenever',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_scheduled_order_requires_a_scheduled_at(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'scheduled',
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_rejects_a_past_scheduled_at(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'scheduled',
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_rejects_an_inactive_service_category(): void
    {
        $user = $this->fundedClient();
        $category = ServiceCategory::factory()->inactive()->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertStatus(422)->assertJsonValidationErrors(['service_category_id']);
    }

    public function test_rejects_an_address_owned_by_another_user(): void
    {
        $user = $this->fundedClient();
        $other = User::factory()->create();
        $category = ServiceCategory::factory()->create();
        $foreignAddress = Address::factory()->for($other)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'service_category_id' => $category->id,
            'address_id' => $foreignAddress->id,
            'type' => 'urgent',
        ])->assertStatus(422)->assertJsonValidationErrors(['address_id']);

        $this->assertDatabaseCount('orders', 0);
    }

    // ---------- Ownership on read ----------

    public function test_index_returns_only_my_orders(): void
    {
        $me = $this->fundedClient();
        $other = User::factory()->create();
        Order::factory()->for($me, 'client')->count(2)->create();
        Order::factory()->for($other, 'client')->create();

        $this->actingAs($me, 'sanctum')->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_returns_my_order(): void
    {
        $me = $this->fundedClient();
        $order = Order::factory()->for($me, 'client')->create();

        $this->actingAs($me, 'sanctum')->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_cannot_view_another_users_order(): void
    {
        $me = $this->fundedClient();
        $order = Order::factory()->create(); // belongs to a different client

        $this->actingAs($me, 'sanctum')->getJson("/api/orders/{$order->id}")
            ->assertNotFound();
    }
}
