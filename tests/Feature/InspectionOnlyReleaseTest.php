<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\EscrowService;
use App\Services\QuoteService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionOnlyReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    /** @return array{0: Order, 1: User, 2: Technician} — Accepted order, 50.00 inspection held. */
    private function acceptedOrderWithHold(): array
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
            'inspection_fee' => '50.00',
        ]);
        app(EscrowService::class)->holdFunds($order, '50.00', PaymentType::Inspection, "insp:{$order->id}", "op:{$order->id}");
        $order->update(['status' => OrderStatus::Accepted]);

        return [$order, $client, $tech];
    }

    private function pendingQuote(Order $order, string $expiresAt): Quote
    {
        return Quote::create([
            'order_id' => $order->id,
            'technician_id' => $order->technician_id,
            'type' => QuoteType::Initial,
            'labor_cost' => '80.00',
            'warranty_days' => 0,
            'status' => QuoteStatus::Pending,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_rejecting_a_quote_releases_the_inspection_fee_to_the_tech(): void
    {
        [$order, , $tech] = $this->acceptedOrderWithHold();
        $quote = $this->pendingQuote($order, now()->addDay());

        app(QuoteService::class)->reject($quote);

        $this->assertSame(OrderStatus::InspectionOnly, $order->refresh()->status);
        // 50 − 10% commission = 45 to the tech for the diagnostic visit.
        $this->assertSame(45.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'released']);
    }

    public function test_expiring_a_quote_releases_the_inspection_fee_to_the_tech(): void
    {
        [$order, , $tech] = $this->acceptedOrderWithHold();
        $this->pendingQuote($order, now()->subHour());

        app(QuoteService::class)->expireStaleQuotes();

        $this->assertSame(OrderStatus::InspectionOnly, $order->refresh()->status);
        $this->assertSame(45.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
    }
}
