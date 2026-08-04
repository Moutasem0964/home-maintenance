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
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReleaseSettledOrdersTest extends TestCase
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

    /** A completed order with a 100.00 repair hold and the given dispute deadline. */
    private function completedOrderWithHold(Carbon $deadline): Order
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

        $this->escrow()->holdFunds($order, '100.00', PaymentType::Repair, "hold:{$order->id}", "op:{$order->id}");
        $order->update(['status' => OrderStatus::Completed, 'dispute_deadline_at' => $deadline]);

        return $order;
    }

    public function test_releases_holds_after_the_dispute_window_closes(): void
    {
        $order = $this->completedOrderWithHold(now()->subHour());

        $this->escrow()->releaseSettledOrders();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'released']);

        // Technician is paid 90 (100 − 10% commission).
        $techWallet = $order->technician->user->wallet()->firstOrFail();
        $this->assertSame(90.0, (float) $techWallet->available_balance);
    }

    public function test_does_not_release_within_the_dispute_window(): void
    {
        $order = $this->completedOrderWithHold(now()->addHours(10));

        $this->escrow()->releaseSettledOrders();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'held']);
    }

    public function test_does_not_release_when_a_dispute_is_open(): void
    {
        $order = $this->completedOrderWithHold(now()->subHour());
        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $order->client_id,
            'reason' => DisputeReason::FaultReturned,
            'status' => DisputeStatus::Open,
        ]);

        $this->escrow()->releaseSettledOrders();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'held']);
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->artisan('orders:release-holds')->assertSuccessful();
    }
}
