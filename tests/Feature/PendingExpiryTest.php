<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AssignmentService;
use App\Services\EscrowService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class); // pending_expiry_minutes = 10
    }

    private function service(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    /**
     * A pending order with a 50.00 inspection fee held (client left with 450 of 500).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: Order, 1: User}
     */
    private function heldPendingOrder(array $overrides = []): array
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, '500.00', 'seed-'.uniqid());

        /** @var Order $order */
        $order = Order::factory()->create(array_merge([
            'client_id' => $client->id,
            'status' => OrderStatus::Pending,
            'commission_rate' => '0.1000',
            'inspection_fee' => '50.00',
        ], $overrides));

        app(EscrowService::class)->holdFunds($order, '50.00', PaymentType::Inspection, "insp:{$order->id}", "op:{$order->id}");

        return [$order, $client];
    }

    public function test_expires_an_urgent_order_that_sat_pending_too_long(): void
    {
        [$order, $client] = $this->heldPendingOrder([
            'type' => OrderType::Urgent,
            'created_at' => now()->subMinutes(11),
        ]);

        $this->assertSame(1, $this->service()->expireStalePending());

        $order->refresh();
        $this->assertSame(OrderStatus::Expired, $order->status);
        $this->assertSame(500.0, (float) $client->wallet()->firstOrFail()->available_balance); // refunded
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
    }

    public function test_expires_a_scheduled_order_once_its_appointment_time_passes(): void
    {
        [$order, $client] = $this->heldPendingOrder([
            'type' => OrderType::Scheduled,
            'scheduled_at' => now()->subHour(),
        ]);

        $this->assertSame(1, $this->service()->expireStalePending());

        $order->refresh();
        $this->assertSame(OrderStatus::Expired, $order->status);
        $this->assertSame(500.0, (float) $client->wallet()->firstOrFail()->available_balance);
    }

    public function test_leaves_a_fresh_urgent_order_alone(): void
    {
        [$order, $client] = $this->heldPendingOrder([
            'type' => OrderType::Urgent,
            'created_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(0, $this->service()->expireStalePending());

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(450.0, (float) $client->wallet()->firstOrFail()->available_balance); // still held
    }

    public function test_leaves_a_future_scheduled_order_alone(): void
    {
        [$order] = $this->heldPendingOrder([
            'type' => OrderType::Scheduled,
            'scheduled_at' => now()->addDays(2),
        ]);

        $this->assertSame(0, $this->service()->expireStalePending());
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_ignores_orders_that_are_no_longer_pending(): void
    {
        [$order] = $this->heldPendingOrder([
            'type' => OrderType::Urgent,
            'created_at' => now()->subMinutes(11),
            'status' => OrderStatus::Accepted,
        ]);

        $this->assertSame(0, $this->service()->expireStalePending());
        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
    }
}
