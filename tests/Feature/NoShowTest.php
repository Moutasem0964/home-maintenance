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

    public function test_tech_reporting_a_client_no_show_raises_a_flag_without_paying(): void
    {
        [$order, , $tech] = $this->acceptedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/client")->assertOk();

        // Report-only: no money moves, the order carries on, a claim awaits admin review.
        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status);
        $this->assertSame(0.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('technician_flags', [
            'order_id' => $order->id, 'reason' => 'client_no_show', 'status' => 'open',
        ]);
    }

    public function test_a_second_client_no_show_report_is_blocked(): void
    {
        [$order, , $tech] = $this->acceptedOrder();
        $techUser = $tech->user()->firstOrFail();

        $this->actingAs($techUser, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/client")->assertOk();
        $this->actingAs($techUser, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/client")->assertStatus(409);
    }

    public function test_admin_confirms_a_client_no_show_releasing_the_fee_to_the_tech(): void
    {
        [$order, , $tech] = $this->acceptedOrder();
        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/client")->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/no-show/resolve", ['outcome' => 'confirmed'])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::NoShow, $order->status);
        // Full 50 to the tech — no commission on the inspection fee.
        $this->assertSame(50.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'released']);
        $this->assertDatabaseHas('technician_flags', [
            'order_id' => $order->id, 'reason' => 'client_no_show', 'status' => 'reviewed', 'outcome' => 'upheld',
        ]);
    }

    public function test_admin_dismisses_a_client_no_show_leaving_the_order_active(): void
    {
        [$order, , $tech] = $this->acceptedOrder();
        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/client")->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/no-show/resolve", ['outcome' => 'dismissed'])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status); // carries on
        $this->assertSame(0.0, (float) $tech->user->wallet()->firstOrFail()->available_balance); // no payout
        $this->assertDatabaseHas('technician_flags', [
            'order_id' => $order->id, 'reason' => 'client_no_show', 'status' => 'reviewed', 'outcome' => 'dismissed',
        ]);
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

    // ---------- technician no-show (client reports → admin resolves) ----------

    public function test_client_reporting_a_tech_no_show_raises_a_flag_without_acting(): void
    {
        [$order, $client, $tech] = $this->acceptedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/no-show/technician")->assertOk();

        // No refund, no status change — the money stays held, the order is still accepted.
        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status);
        $this->assertSame(450.0, (float) $client->wallet()->firstOrFail()->available_balance); // 500 − 50 held
        $this->assertDatabaseHas('technician_flags', [
            'technician_id' => $tech->id, 'order_id' => $order->id, 'reason' => 'no_show', 'status' => 'open',
        ]);
    }

    public function test_a_second_no_show_report_is_blocked(): void
    {
        [$order, $client] = $this->acceptedOrder();

        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/technician")->assertOk();
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/technician")->assertStatus(409);
    }

    public function test_admin_confirms_a_no_show_refunding_the_client(): void
    {
        [$order, $client] = $this->acceptedOrder();
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/technician")->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/no-show/resolve", ['outcome' => 'confirmed'])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::NoShow, $order->status);
        $this->assertSame(500.0, (float) $client->wallet()->firstOrFail()->available_balance); // refunded
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('technician_flags', ['order_id' => $order->id, 'status' => 'reviewed', 'outcome' => 'upheld']);
    }

    public function test_admin_dismisses_a_no_show_leaving_the_order_untouched(): void
    {
        [$order, $client] = $this->acceptedOrder();
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/technician")->assertOk();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/no-show/resolve", ['outcome' => 'dismissed'])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status); // carries on
        $this->assertSame(450.0, (float) $client->wallet()->firstOrFail()->available_balance); // no refund
        $this->assertDatabaseHas('technician_flags', ['order_id' => $order->id, 'status' => 'reviewed', 'outcome' => 'dismissed']);
    }

    public function test_only_an_admin_can_resolve_a_no_show(): void
    {
        [$order, $client] = $this->acceptedOrder();
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/no-show/technician")->assertOk();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/no-show/resolve", ['outcome' => 'confirmed'])->assertForbidden();
    }

    public function test_resolving_without_an_open_report_is_rejected(): void
    {
        [$order] = $this->acceptedOrder();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/orders/{$order->id}/no-show/resolve", ['outcome' => 'confirmed'])->assertStatus(409);
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
