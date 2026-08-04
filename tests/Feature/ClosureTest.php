<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    /** @return array{0: Order, 1: User, 2: Technician} — in-progress order, its client, its technician. */
    private function inProgressOrder(): array
    {
        $client = User::factory()->create();
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
        ]);

        return [$order, $client, $tech];
    }

    /** Tech requests closure, then the client fetches the code to read to them. */
    private function activeCode(User $client, Technician $tech, Order $order): string
    {
        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/request")->assertOk();

        return $this->actingAs($client, 'sanctum')
            ->getJson("/api/orders/{$order->id}/closure/code")->json('code');
    }

    // ---------- request (technician) ----------

    public function test_request_requires_authentication(): void
    {
        [$order] = $this->inProgressOrder();

        $this->postJson("/api/orders/{$order->id}/closure/request")->assertUnauthorized();
    }

    public function test_only_the_assigned_technician_can_request_closure(): void
    {
        [$order, $client] = $this->inProgressOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/request")->assertForbidden();
    }

    public function test_technician_requests_closure_and_a_code_is_minted(): void
    {
        [$order, , $tech] = $this->inProgressOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/request")
            ->assertOk()
            ->assertJsonStructure(['message', 'expires_at']);

        $this->assertNotNull($order->refresh()->closure_expires_at);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'closure_generated']);
    }

    public function test_cannot_request_closure_when_the_order_is_not_in_progress(): void
    {
        [$order, , $tech] = $this->inProgressOrder();
        $order->update(['status' => OrderStatus::Accepted]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/request")->assertStatus(409);
    }

    // ---------- fetch code (client) ----------

    public function test_client_fetches_the_active_code(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/request")->assertOk();

        $this->actingAs($client, 'sanctum')
            ->getJson("/api/orders/{$order->id}/closure/code")
            ->assertOk()
            ->assertJsonStructure(['code', 'expires_at']);
    }

    public function test_only_the_client_can_fetch_the_code(): void
    {
        [$order, , $tech] = $this->inProgressOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/request")->assertOk();

        // The technician must never read the code from the server (SRS note 4).
        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->getJson("/api/orders/{$order->id}/closure/code")->assertForbidden();
    }

    public function test_fetching_the_code_is_404_when_none_has_been_requested(): void
    {
        [$order, $client] = $this->inProgressOrder();

        $this->actingAs($client, 'sanctum')
            ->getJson("/api/orders/{$order->id}/closure/code")->assertStatus(404);
    }

    // ---------- verify (technician) ----------

    public function test_only_the_assigned_technician_can_verify(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();
        $code = $this->activeCode($client, $tech, $order);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $code])->assertForbidden();
    }

    public function test_technician_completes_the_order_with_the_correct_code(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();
        $code = $this->activeCode($client, $tech, $order);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $code])
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->closure_verified_at);
        $this->assertNotNull($order->dispute_deadline_at);
        $this->assertNull($order->closure_code);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'completed']);
    }

    public function test_a_wrong_code_is_rejected_and_counts_an_attempt(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();
        $real = $this->activeCode($client, $tech, $order);
        $wrong = $real === '0000' ? '1111' : '0000';

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $wrong])->assertStatus(422);

        $order->refresh();
        $this->assertSame(1, $order->closure_attempts);
        $this->assertSame(OrderStatus::InProgress, $order->status);
    }

    public function test_locks_out_after_the_maximum_attempts(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();
        $real = $this->activeCode($client, $tech, $order);
        $wrong = $real === '0000' ? '1111' : '0000';
        $techUser = $tech->user()->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($techUser, 'sanctum')
                ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $wrong])->assertStatus(422);
        }

        // Now locked — even the correct code is refused.
        $this->actingAs($techUser, 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $real])->assertStatus(422);

        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();
        $code = $this->activeCode($client, $tech, $order);

        $this->travel(11)->minutes(); // TTL is 10 minutes

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $code])->assertStatus(422);
    }

    public function test_cannot_verify_when_the_order_is_not_in_progress(): void
    {
        [$order, $client, $tech] = $this->inProgressOrder();
        $code = $this->activeCode($client, $tech, $order);
        $order->update(['status' => OrderStatus::Completed]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/closure/verify", ['code' => $code])->assertStatus(409);
    }
}
