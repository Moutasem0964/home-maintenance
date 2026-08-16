<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Enums\TechnicianStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClosureService;
use App\Services\PlatformService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WarrantyReassignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    private function when(): Carbon
    {
        return now()->addDay()->startOfHour();
    }

    /** A completed parent order with an approved 80.00-labor quote (so a warranty exists). */
    private function completedParent(Technician $tech): Order
    {
        $client = User::factory()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::Completed,
            'warranty_until' => now()->addDays(10),
        ]);
        Quote::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'type' => QuoteType::Initial,
            'labor_cost' => '80.00',
            'warranty_days' => 14,
            'status' => QuoteStatus::Approved,
            'expires_at' => now()->addDay(),
        ]);

        return $order;
    }

    /** A warranty child order in progress, assigned to $assignedTech. */
    private function warrantyInProgress(Order $parent, Technician $assignedTech, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'client_id' => $parent->client_id,
            'technician_id' => $assignedTech->id,
            'parent_order_id' => $parent->id,
            'kind' => OrderKind::Warranty,
            'type' => OrderType::Urgent,
            'status' => OrderStatus::InProgress,
            'inspection_fee' => '0.00',
            'commission_rate' => '0.0000',
        ], $overrides));
    }

    // ---------- trigger 1: claim falls back to the pool ----------

    public function test_claim_falls_back_to_the_pool_when_the_original_tech_is_banned(): void
    {
        $tech = Technician::factory()->create(['status' => TechnicianStatus::Banned]);
        $parent = $this->completedParent($tech);

        $this->actingAs($parent->client, 'sanctum')
            ->postJson("/api/orders/{$parent->id}/warranty-claim", ['scheduled_at' => $this->when()->toDateTimeString()])
            ->assertCreated();

        $warranty = Order::where('parent_order_id', $parent->id)->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $warranty->status);
        $this->assertNull($warranty->technician_id);
        $this->assertDatabaseMissing('appointments', ['order_id' => $warranty->id]);
    }

    // ---------- trigger 2: booked tech no-shows, admin confirms ----------

    public function test_admin_confirming_a_warranty_no_show_reassigns_to_the_pool(): void
    {
        $original = Technician::factory()->active()->create();
        $parent = $this->completedParent($original);
        $warranty = $this->warrantyInProgress($parent, $original, [
            'type' => OrderType::Scheduled,
            'scheduled_at' => now()->subMinutes(30),
        ]);
        Appointment::create([
            'order_id' => $warranty->id,
            'technician_id' => $original->id,
            'type' => AppointmentType::Inspection,
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addMinutes(90),
            'status' => AppointmentStatus::Activated,
        ]);

        // The client reports the booked warranty tech never showed.
        $this->actingAs($parent->client, 'sanctum')
            ->postJson("/api/orders/{$warranty->id}/no-show/technician")->assertOk();

        // The admin confirms it.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/orders/{$warranty->id}/no-show/resolve", ['outcome' => 'confirmed'])->assertOk();

        $warranty->refresh();
        $this->assertSame(OrderStatus::Pending, $warranty->status); // reassigned, not closed
        $this->assertNull($warranty->technician_id);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $warranty->id, 'event_type' => 'warranty_reassigned',
        ]);
        // The original tech's slot was freed and their offense upheld.
        $this->assertDatabaseHas('appointments', ['order_id' => $warranty->id, 'status' => 'canceled']);
        $this->assertDatabaseHas('technician_flags', [
            'order_id' => $warranty->id, 'reason' => 'no_show', 'status' => 'reviewed',
        ]);
    }

    // ---------- substitute payout ----------

    private function fundPlatform(string $amount): void
    {
        $platform = app(PlatformService::class)->account();
        app(WalletService::class)->topUp($platform, $amount, 'seed-'.uniqid());
    }

    public function test_a_substitute_completing_a_warranty_is_paid_the_original_labor_cost(): void
    {
        $original = Technician::factory()->active()->create();
        $substitute = Technician::factory()->active()->create();
        Wallet::create(['user_id' => $substitute->user_id]);
        $this->fundPlatform('500.00');

        $parent = $this->completedParent($original);
        $warranty = $this->warrantyInProgress($parent, $substitute, ['closure_expires_at' => now()->subMinute()]);

        app(ClosureService::class)->autoCompleteStaleClosures();

        $this->assertSame(OrderStatus::Completed, $warranty->refresh()->status);
        $this->assertSame(80.0, (float) $substitute->user->wallet()->firstOrFail()->available_balance);
        $this->assertSame(420.0, (float) app(PlatformService::class)->account()->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('payments', [
            'order_id' => $warranty->id, 'type' => 'repair', 'status' => 'released', 'amount' => '80.00',
        ]);
    }

    public function test_the_original_tech_honouring_the_warranty_is_never_paid(): void
    {
        $original = Technician::factory()->active()->create();
        Wallet::create(['user_id' => $original->user_id]);
        $this->fundPlatform('500.00');

        $parent = $this->completedParent($original);
        $warranty = $this->warrantyInProgress($parent, $original, ['closure_expires_at' => now()->subMinute()]);

        app(ClosureService::class)->autoCompleteStaleClosures();

        $this->assertSame(0.0, (float) $original->user->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseMissing('payments', ['order_id' => $warranty->id]);
    }

    public function test_a_short_platform_wallet_leaves_the_payout_pending_until_an_admin_tops_up(): void
    {
        $original = Technician::factory()->active()->create();
        $substitute = Technician::factory()->active()->create();
        Wallet::create(['user_id' => $substitute->user_id]);
        // Platform wallet intentionally left at 0.

        $parent = $this->completedParent($original);
        $warranty = $this->warrantyInProgress($parent, $substitute, ['closure_expires_at' => now()->subMinute()]);

        app(ClosureService::class)->autoCompleteStaleClosures();

        // Not enough funds — the obligation is recorded but unpaid.
        $this->assertDatabaseHas('payments', [
            'order_id' => $warranty->id, 'type' => 'repair', 'status' => 'pending', 'amount' => '80.00',
        ]);
        $this->assertSame(0.0, (float) $substitute->user->wallet()->firstOrFail()->available_balance);

        // Admin tops up the platform wallet — the payout settles immediately.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/platform-wallet/top-up', ['amount' => '100.00'])->assertOk();

        $this->assertSame(80.0, (float) $substitute->user->wallet()->firstOrFail()->available_balance);
        $this->assertDatabaseHas('payments', [
            'order_id' => $warranty->id, 'status' => 'released',
        ]);
    }

    public function test_the_platform_top_up_endpoint_is_admin_only(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client, 'sanctum')
            ->postJson('/api/admin/platform-wallet/top-up', ['amount' => '100.00'])->assertForbidden();
    }
}
