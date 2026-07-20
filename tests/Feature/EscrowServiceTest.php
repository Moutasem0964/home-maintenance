<?php

namespace Tests\Feature;

use App\Enums\BalanceType;
use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\TechnicianStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\EscrowService;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Executable spec for EscrowService
 */
class EscrowServiceTest extends TestCase
{
    use RefreshDatabase;

    private EscrowService $escrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->escrow = app(EscrowService::class);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Client with a funded wallet (cached columns + matching deposit ledger entry). */
    private function makeClient(string $balance = '500.00'): User
    {
        $client = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $client->id]);

        $wallet->transactions()->create([
            'type' => 'deposit',
            'balance_type' => BalanceType::Available,
            'amount' => $balance,
            'reference' => 'test:seed:'.$wallet->id,
            'description' => 'test seed',
        ]);

        $wallet->available_balance = $balance;
        $wallet->save();

        return $client;
    }

    private function makeTechnician(): Technician
    {
        $user = User::factory()->technicianRole()->create();
        Wallet::create(['user_id' => $user->id]);

        return Technician::create([
            'user_id' => $user->id,
            'status' => TechnicianStatus::Active,
        ]);
    }

    private function makeOrder(User $client, ?Technician $tech = null, OrderStatus $status = OrderStatus::Pending): Order
    {
        $category = ServiceCategory::create(['name' => 'كهرباء', 'is_active' => true]);

        return Order::create([
            'client_id' => $client->id,
            'technician_id' => $tech?->id,
            'service_category_id' => $category->id,
            'lat' => '33.5138000',
            'lng' => '36.2765000',
            'type' => OrderType::Urgent,
            'status' => $status,
            'commission_rate' => '0.1000', // snapshot — NOT read from app_settings at release time
            'inspection_fee' => '50.00',
        ]);
    }

    private function wallet(User $user): Wallet
    {
        return $user->wallet()->first()->refresh();
    }

    /** The core invariant: cached balances must equal the ledger sums, always. */
    private function assertLedgerMatchesCache(Wallet $wallet): void
    {
        $wallet->refresh();
        $this->assertSame(
            (float) $wallet->available_balance,
            (float) $wallet->ledgerBalance(BalanceType::Available),
            "available cache drifted from ledger for wallet {$wallet->id}"
        );
        $this->assertSame(
            (float) $wallet->held_balance,
            (float) $wallet->ledgerBalance(BalanceType::Held),
            "held cache drifted from ledger for wallet {$wallet->id}"
        );
    }

    // ── holdFunds ──────────────────────────────────────────────────────────

    public function test_hold_moves_money_from_available_to_held(): void
    {
        $client = $this->makeClient('500.00');
        $order = $this->makeOrder($client);

        $payment = $this->escrow->holdFunds($order, '50.00', PaymentType::Inspection, 'key-1', 'op-hold-1');

        $this->assertSame(PaymentStatus::Held, $payment->status);
        $this->assertSame(450.00, (float) $this->wallet($client)->available_balance);
        $this->assertSame(50.00, (float) $this->wallet($client)->held_balance);
        $this->assertLedgerMatchesCache($this->wallet($client));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'funds_held']);
    }

    public function test_same_idempotency_key_charges_only_once(): void
    {
        $client = $this->makeClient('500.00');
        $order = $this->makeOrder($client);

        $first = $this->escrow->holdFunds($order, '50.00', PaymentType::Inspection, 'key-dup', 'op-hold-dup');
        $second = $this->escrow->holdFunds($order, '50.00', PaymentType::Inspection, 'key-dup', 'op-hold-dup');

        $this->assertTrue($first->is($second), 'second call must return the SAME payment');
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(450.00, (float) $this->wallet($client)->available_balance, 'double-click must not double-charge');
        $this->assertLedgerMatchesCache($this->wallet($client));
    }

    public function test_insufficient_balance_throws_and_writes_nothing(): void
    {
        $client = $this->makeClient('10.00');
        $order = $this->makeOrder($client);

        try {
            $this->escrow->holdFunds($order, '50.00', PaymentType::Inspection, 'key-poor', 'op-hold-poor');
            $this->fail('expected InsufficientBalanceException');
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->assertSame(0, $order->payments()->count(), 'no payment row on failure');
        $this->assertSame(10.00, (float) $this->wallet($client)->available_balance, 'balance untouched');
        $this->assertSame(0.00, (float) $this->wallet($client)->held_balance);
        $this->assertLedgerMatchesCache($this->wallet($client));
    }

    // ── releaseFunds ───────────────────────────────────────────────────────

    public function test_release_pays_technician_minus_snapshotted_commission(): void
    {
        $client = $this->makeClient('500.00');
        $tech = $this->makeTechnician();
        $order = $this->makeOrder($client, $tech, OrderStatus::Completed);
        $this->escrow->holdFunds($order, '100.00', PaymentType::Repair, 'key-rel', 'op-hold-rel');

        $this->escrow->releaseFunds($order, 'op-release-rel');

        // commission_rate snapshot = 0.10 → tech gets 90, platform keeps 10
        $this->assertSame(90.00, (float) $this->wallet($tech->user)->available_balance);
        $this->assertSame(0.00, (float) $this->wallet($client)->held_balance, 'client held emptied');
        $this->assertSame(PaymentStatus::Released, $order->payments()->first()->refresh()->status);
        $this->assertLedgerMatchesCache($this->wallet($client));
        $this->assertLedgerMatchesCache($this->wallet($tech->user));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'funds_released']);
    }

    public function test_release_refuses_when_order_is_disputed(): void
    {
        $client = $this->makeClient('500.00');
        $tech = $this->makeTechnician();
        $order = $this->makeOrder($client, $tech, OrderStatus::Disputed);
        $this->escrow->holdFunds($order, '100.00', PaymentType::Repair, 'key-disp', 'op-hold-disp');
        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $client->id,
            'reason' => DisputeReason::FaultReturned,
            'status' => DisputeStatus::Open,
        ]);

        $this->escrow->releaseFunds($order, 'op-release-disp'); // must be a silent no-op, not an exception

        $this->assertSame(0.00, (float) $this->wallet($tech->user)->available_balance, 'disputed money must stay frozen');
        $this->assertSame(100.00, (float) $this->wallet($client)->held_balance);
        $this->assertSame(PaymentStatus::Held, $order->payments()->first()->refresh()->status);
    }

    // ── refund ─────────────────────────────────────────────────────────────

    public function test_partial_refund_returns_money_to_client(): void
    {
        $client = $this->makeClient('500.00');
        $order = $this->makeOrder($client);
        $this->escrow->holdFunds($order, '50.00', PaymentType::Inspection, 'key-ref', 'op-hold-ref');

        $this->escrow->refund($order, '35.00', 'op-refund-ref'); // e.g. late-cancel split: 15 stays for the technician

        $wallet = $this->wallet($client);
        $this->assertSame(485.00, (float) $wallet->available_balance); // 450 + 35 back
        $this->assertSame(15.00, (float) $wallet->held_balance);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $order->payments()->first()->refresh()->status);
        $this->assertLedgerMatchesCache($wallet);
    }
}
