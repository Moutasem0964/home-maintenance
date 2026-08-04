<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuotePart;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteDecisionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Order, 2: Quote} — client, accepted order, pending quote (total 150). */
    private function scenario(string $balance = '1000.00'): array
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, $balance, 'seed-'.$client->id);

        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::Accepted,
            'commission_rate' => '0.1000',
        ]);
        $quote = Quote::factory()->create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'labor_cost' => '100.00',
            'status' => QuoteStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);
        QuotePart::factory()->create(['quote_id' => $quote->id, 'price' => '50.00']);

        return [$client->refresh(), $order, $quote];
    }

    public function test_approve_requires_authentication(): void
    {
        [, , $quote] = $this->scenario();

        $this->postJson("/api/quotes/{$quote->id}/approve")->assertUnauthorized();
    }

    public function test_only_the_client_can_approve(): void
    {
        [, , $quote] = $this->scenario();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/quotes/{$quote->id}/approve")->assertForbidden();
    }

    public function test_client_approves_holds_the_repair_fee_and_starts_work(): void
    {
        [$client, $order, $quote] = $this->scenario('1000.00');

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
        $this->assertSame(QuoteStatus::Approved, $quote->refresh()->status);

        $wallet = $client->wallet()->firstOrFail();
        $this->assertSame(850.0, (float) $wallet->available_balance); // 1000 − 150
        $this->assertSame(150.0, (float) $wallet->held_balance);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'type' => 'repair', 'status' => 'held', 'amount' => '150.00',
        ]);
    }

    public function test_approve_with_insufficient_balance_is_rejected_and_changes_nothing(): void
    {
        [$client, $order, $quote] = $this->scenario('100.00'); // below the 150 total

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/approve")->assertStatus(422);

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertSame(QuoteStatus::Pending, $quote->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cannot_approve_a_non_pending_quote(): void
    {
        [$client, , $quote] = $this->scenario();
        $quote->update(['status' => QuoteStatus::Approved]);

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/approve")->assertStatus(409);
    }

    public function test_cannot_approve_an_expired_quote(): void
    {
        [$client, , $quote] = $this->scenario();
        $quote->update(['expires_at' => now()->subHour()]);

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/approve")->assertStatus(409);
    }

    public function test_client_rejects_and_the_order_closes_as_inspection_only(): void
    {
        [$client, $order, $quote] = $this->scenario();

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'inspection_only');

        $this->assertSame(OrderStatus::InspectionOnly, $order->refresh()->status);
        $this->assertSame(QuoteStatus::Rejected, $quote->refresh()->status);
    }

    public function test_only_the_client_can_reject(): void
    {
        [, , $quote] = $this->scenario();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/quotes/{$quote->id}/reject")->assertForbidden();
    }
}
