<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\EscrowService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    /** @return array{0: Order, 1: User, 2: Technician} — an Accepted order with a 50.00 inspection hold. */
    private function acceptedOrder(): array
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

    // ---------- client no-show (technician reports) ----------

    public function test_client_no_show_releases_the_inspection_fee_to_the_tech(): void
    {
        [$order, , $tech] = $this->acceptedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/client")->assertOk();

        // Full 50 to the tech — no commission on the inspection fee.
        $this->assertSame(OrderStatus::NoShow, $order->refresh()->status);
        $this->assertSame(50.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'released']);
    }

    public function test_only_the_assigned_tech_can_report_a_client_no_show(): void
    {
        [$order, $client] = $this->acceptedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/client")->assertForbidden();
    }

    public function test_client_no_show_only_applies_when_the_tech_is_on_site(): void
    {
        [$order, , $tech] = $this->acceptedOrder();
        $order->update(['status' => OrderStatus::InProgress]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/client")->assertStatus(409);
    }

    // ---------- technician no-show (client reports) ----------

    public function test_technician_no_show_refunds_the_client(): void
    {
        [$order, $client] = $this->acceptedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/technician")->assertOk();

        $this->assertSame(OrderStatus::NoShow, $order->refresh()->status);
        $this->assertSame(500.0, (float) $client->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
    }

    public function test_only_the_client_can_report_a_technician_no_show(): void
    {
        [$order, , $tech] = $this->acceptedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/technician")->assertForbidden();
    }

    public function test_no_show_requires_authentication(): void
    {
        [$order] = $this->acceptedOrder();

        $this->postJson("/api/orders/{$order->id}/no-show/technician")->assertUnauthorized();
        $this->postJson("/api/orders/{$order->id}/no-show/client")->assertUnauthorized();
    }
}
