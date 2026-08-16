<?php

namespace Tests\Feature;

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\EscrowService;
use App\Services\WalletService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
    }

    private function escrow(): EscrowService
    {
        return app(EscrowService::class);
    }

    /**
     * A completed, in-window order with the given held payments.
     *
     * @param  array<string, string>  $holds  paymentType value => amount, e.g. ['repair' => '100.00']
     * @return array{0: Order, 1: User, 2: Technician}
     */
    private function completedOrder(array $holds = ['repair' => '100.00']): array
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, '500.00', 'seed-'.uniqid());

        $tech = Technician::factory()->active()->create();
        Wallet::create(['user_id' => $tech->user_id]);

        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
            'commission_rate' => '0.1000',
        ]);

        foreach ($holds as $type => $amount) {
            $this->escrow()->holdFunds(
                $order,
                $amount,
                PaymentType::from($type),
                "hold:{$type}:{$order->id}",
                "op:{$type}:{$order->id}",
            );
        }

        $order->update(['status' => OrderStatus::Completed, 'dispute_deadline_at' => now()->addHours(24)]);

        return [$order, $client, $tech];
    }

    public function test_disputes_index_requires_authentication(): void
    {
        $this->getJson('/api/disputes')->assertUnauthorized();
    }

    public function test_client_lists_only_their_own_disputes(): void
    {
        [$order, $client] = $this->completedOrder();
        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $client->id,
            'reason' => DisputeReason::FaultReturned,
            'status' => DisputeStatus::Open,
        ]);

        // Another client's dispute that must NOT appear in the first client's list.
        [$otherOrder, $otherClient] = $this->completedOrder();
        Dispute::create([
            'order_id' => $otherOrder->id,
            'raised_by' => $otherClient->id,
            'reason' => DisputeReason::Other,
            'status' => DisputeStatus::Open,
        ]);

        $this->actingAs($client, 'sanctum')->getJson('/api/disputes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $order->id);
    }

    /** Open a dispute directly (bypasses the raise endpoint) for resolve-side tests. */
    private function openDisputeOn(Order $order, User $client): Dispute
    {
        $dispute = Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $client->id,
            'reason' => DisputeReason::FaultReturned,
            'status' => DisputeStatus::Open,
        ]);
        $order->update(['status' => OrderStatus::Disputed]);

        return $dispute;
    }

    // ---------- raise (client) ----------

    public function test_raise_requires_authentication(): void
    {
        [$order] = $this->completedOrder();

        $this->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])->assertUnauthorized();
    }

    public function test_only_the_client_can_raise_a_dispute(): void
    {
        [$order, , $tech] = $this->completedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])
            ->assertForbidden();
    }

    public function test_client_raises_a_dispute_within_the_window(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'home_damage', 'description' => 'Scratched the wall.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');

        $this->assertSame(OrderStatus::Disputed, $order->refresh()->status);
        $this->assertDatabaseHas('disputes', ['order_id' => $order->id, 'reason' => 'home_damage', 'status' => 'open']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'disputed']);
    }

    public function test_rejects_an_unknown_reason(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'aliens'])
            ->assertStatus(422);
    }

    public function test_cannot_raise_after_the_window_closes(): void
    {
        [$order, $client] = $this->completedOrder();
        $order->update(['dispute_deadline_at' => now()->subMinute()]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])
            ->assertStatus(409);
    }

    public function test_cannot_raise_when_the_order_is_not_completed(): void
    {
        [$order, $client] = $this->completedOrder();
        $order->update(['status' => OrderStatus::InProgress]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])
            ->assertStatus(409);
    }

    public function test_cannot_raise_a_second_open_dispute(): void
    {
        [$order, $client] = $this->completedOrder();
        $this->openDisputeOn($order, $client);
        $order->update(['status' => OrderStatus::Completed]); // pretend still completed to reach the guard

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])
            ->assertStatus(409);
    }

    public function test_raising_freezes_the_release_cron(): void
    {
        [$order, $client] = $this->completedOrder();
        $order->update(['dispute_deadline_at' => now()->addHours(24)]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])->assertCreated();

        // Even once the window elapses, an open dispute keeps the money held.
        $this->travel(25)->hours();
        $this->escrow()->releaseSettledOrders();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'held']);
    }

    // ---------- resolve (admin) ----------

    public function test_resolve_requires_admin(): void
    {
        [$order, $client] = $this->completedOrder();
        $dispute = $this->openDisputeOn($order, $client);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'full_refund'])
            ->assertForbidden();
    }

    public function test_full_refund_returns_all_held_money_to_the_client(): void
    {
        [$order, $client] = $this->completedOrder(['inspection' => '20.00', 'repair' => '100.00']);
        $dispute = $this->openDisputeOn($order, $client);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'full_refund'])
            ->assertOk()->assertJsonPath('data.resolution', 'full_refund');

        // Client started with 500, held 120, and now has it all back available.
        $this->assertSame(500.0, (float) $client->wallet()->firstOrFail()->available_balance);
        $this->assertSame(0.0, (float) $client->wallet()->firstOrFail()->held_balance);
        $this->assertSame(OrderStatus::Resolved, $order->refresh()->status);
        $this->assertSame(DisputeStatus::Resolved, $dispute->refresh()->status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
    }

    public function test_release_to_technician_pays_the_technician(): void
    {
        [$order, $client, $tech] = $this->completedOrder(['repair' => '100.00']);
        $dispute = $this->openDisputeOn($order, $client);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'release_to_technician'])
            ->assertOk();

        // 100 − 10% commission = 90 to the tech, 10 to the platform.
        $this->assertSame(90.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertSame(OrderStatus::Resolved, $order->refresh()->status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'released']);
    }

    public function test_partial_refund_splits_between_client_and_technician(): void
    {
        [$order, $client, $tech] = $this->completedOrder(['repair' => '100.00']);
        $dispute = $this->openDisputeOn($order, $client);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'partial_refund', 'refund_amount' => '40.00'])
            ->assertOk();

        // Refund 40 to the client; release 60 to the tech minus 10% (= 54), platform gets 6.
        $this->assertSame(440.0, (float) $client->wallet()->firstOrFail()->available_balance);
        $this->assertSame(54.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertSame(0.0, (float) $client->wallet()->firstOrFail()->held_balance);
    }

    public function test_partial_refund_allocates_fifo_across_multiple_holds(): void
    {
        [$order, $client, $tech] = $this->completedOrder(['inspection' => '20.00', 'repair' => '100.00']);
        $dispute = $this->openDisputeOn($order, $client);
        $admin = User::factory()->admin()->create();

        // Refund 50 of 120: FIFO refunds the 20 inspection fully + 30 of the repair;
        // the remaining 70 of the repair is released (− 10% = 63 to tech, 7 to platform).
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'partial_refund', 'refund_amount' => '50.00'])
            ->assertOk();

        $this->assertSame(430.0, (float) $client->wallet()->firstOrFail()->available_balance);
        $this->assertSame(63.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertSame(0.0, (float) $client->wallet()->firstOrFail()->held_balance);
    }

    public function test_partial_refund_amount_must_be_within_the_held_total(): void
    {
        [$order, $client] = $this->completedOrder(['repair' => '100.00']);
        $dispute = $this->openDisputeOn($order, $client);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'partial_refund', 'refund_amount' => '999.00'])
            ->assertStatus(422);
    }

    public function test_cannot_resolve_an_already_resolved_dispute(): void
    {
        [$order, $client, $tech] = $this->completedOrder(['repair' => '100.00']);
        $dispute = $this->openDisputeOn($order, $client);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'release_to_technician'])->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/disputes/{$dispute->id}/resolve", ['resolution' => 'full_refund'])->assertStatus(422);
    }

    // ---------- raising during the closure review window (in-progress) ----------

    public function test_client_can_dispute_during_the_closure_review_window(): void
    {
        [$order, $client] = $this->completedOrder();
        // Tech has requested closure: order is back in-progress with an open review window.
        // closure_expires_at isn't mass-assignable (set directly under lock in prod), so assign it directly.
        $order->status = OrderStatus::InProgress;
        $order->closure_expires_at = now()->addMinutes(10);
        $order->dispute_deadline_at = null;
        $order->save();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'home_damage'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');

        $this->assertSame(OrderStatus::Disputed, $order->refresh()->status);
    }

    public function test_cannot_dispute_in_progress_without_a_closure_request(): void
    {
        [$order, $client] = $this->completedOrder();
        $order->update([
            'status' => OrderStatus::InProgress,
            'closure_expires_at' => null, // tech has not requested closure yet
            'dispute_deadline_at' => null,
        ]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])
            ->assertStatus(409);
    }

    public function test_raising_a_dispute_stores_and_returns_evidence_photos(): void
    {
        Storage::fake('local');
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->post("/api/orders/{$order->id}/dispute", [
                'reason' => 'home_damage',
                'description' => 'Scratched the wall.',
                'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.photos');

        $this->assertDatabaseCount('order_photos', 2);
        $this->assertDatabaseHas('order_photos', [
            'order_id' => $order->id, 'kind' => 'dispute', 'uploaded_by' => $client->id,
        ]);
    }

    public function test_a_dispute_can_still_be_raised_without_photos(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'fault_returned'])
            ->assertCreated()
            ->assertJsonCount(0, 'data.photos');

        $this->assertDatabaseCount('order_photos', 0);
    }

    public function test_dispute_photos_are_capped_and_must_be_images(): void
    {
        Storage::fake('local');
        [$order, $client] = $this->completedOrder();

        // Too many.
        $this->actingAs($client, 'sanctum')
            ->post("/api/orders/{$order->id}/dispute", [
                'reason' => 'other',
                'photos' => array_fill(0, 6, UploadedFile::fake()->image('x.jpg')),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // Not an image.
        $this->actingAs($client, 'sanctum')
            ->post("/api/orders/{$order->id}/dispute", [
                'reason' => 'other',
                'photos' => [UploadedFile::fake()->create('evil.pdf', 100)],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
