<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\TechnicianFlagReason;
use App\Enums\TechnicianFlagStatus;
use App\Enums\TechnicianStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\TechnicianFlag;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CancellationService;
use App\Services\EscrowService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    private function acceptedOrder(Technician $tech): Order
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, '500.00', 'seed-'.uniqid());
        Wallet::firstOrCreate(['user_id' => $tech->user_id]);

        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
            'commission_rate' => '0.1000',
            'inspection_fee' => '50.00',
        ]);
        app(EscrowService::class)->holdFunds($order, '50.00', PaymentType::Inspection, "insp:{$order->id}", "op:{$order->id}");
        $order->update(['status' => OrderStatus::Accepted]);

        return $order;
    }

    private function openFlag(Technician $tech): TechnicianFlag
    {
        return TechnicianFlag::create([
            'technician_id' => $tech->id,
            'reason' => TechnicianFlagReason::NoShow,
            'status' => TechnicianFlagStatus::Open,
        ]);
    }

    // ---------- flags are raised automatically ----------

    public function test_technician_withdraw_raises_an_open_flag(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = $this->acceptedOrder($tech);

        app(CancellationService::class)->technicianWithdraw($order);

        $this->assertDatabaseHas('technician_flags', [
            'technician_id' => $tech->id,
            'order_id' => $order->id,
            'reason' => 'withdrawal',
            'status' => 'open',
        ]);
    }

    public function test_technician_no_show_raises_an_open_flag(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = $this->acceptedOrder($tech);

        app(CancellationService::class)->technicianNoShow($order);

        $this->assertDatabaseHas('technician_flags', [
            'technician_id' => $tech->id,
            'reason' => 'no_show',
            'status' => 'open',
        ]);
    }

    public function test_a_client_no_show_raises_no_flag(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = $this->acceptedOrder($tech);

        app(CancellationService::class)->clientNoShow($order); // client's fault

        $this->assertDatabaseCount('technician_flags', 0);
    }

    // ---------- admin assessment ----------

    public function test_admin_lists_open_flags(): void
    {
        $tech = Technician::factory()->active()->create();
        $this->openFlag($tech);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/technician-flags')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.technician_id', $tech->id);
    }

    public function test_listing_flags_requires_admin(): void
    {
        $tech = Technician::factory()->active()->create();

        $this->getJson('/api/admin/technician-flags')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/admin/technician-flags')->assertForbidden();
    }

    public function test_admin_reviews_a_flag(): void
    {
        $tech = Technician::factory()->active()->create();
        $flag = $this->openFlag($tech);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/technician-flags/{$flag->id}/review", ['note' => 'First offense — warning only.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed')
            ->assertJsonPath('data.outcome', 'dismissed');

        $this->assertDatabaseHas('technician_flags', [
            'id' => $flag->id,
            'status' => 'reviewed',
            'outcome' => 'dismissed',
            'reviewed_by' => $admin->id,
            'note' => 'First offense — warning only.',
        ]);
    }

    public function test_suspending_a_tech_resolves_their_open_flags_as_suspended(): void
    {
        $tech = Technician::factory()->active()->create();
        $this->openFlag($tech);
        $this->openFlag($tech);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/technicians/{$tech->id}/suspend", ['note' => 'Repeated withdrawals over two weeks.'])
            ->assertOk();

        $this->assertSame(0, TechnicianFlag::where('technician_id', $tech->id)->where('status', TechnicianFlagStatus::Open)->count());
        $this->assertSame(2, TechnicianFlag::where('technician_id', $tech->id)->where('outcome', 'suspended')->count());
        $this->assertSame(TechnicianStatus::Probation, $tech->refresh()->status);
    }

    public function test_banning_a_tech_resolves_their_open_flags_as_banned(): void
    {
        $tech = Technician::factory()->active()->create();
        $this->openFlag($tech);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/technicians/{$tech->id}/ban")->assertOk();

        $this->assertDatabaseHas('technician_flags', [
            'technician_id' => $tech->id,
            'status' => 'reviewed',
            'outcome' => 'banned',
        ]);
    }
}
