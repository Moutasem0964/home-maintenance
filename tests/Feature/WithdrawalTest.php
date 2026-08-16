<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WithdrawalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
        Storage::fake('local');
    }

    /** @return array{0: Technician, 1: User} a technician whose wallet holds $balance available. */
    private function fundedTech(string $balance = '500.00'): array
    {
        $tech = Technician::factory()->active()->create();
        /** @var User $user */
        $user = $tech->user;
        Wallet::create(['user_id' => $user->id]);
        app(WalletService::class)->topUp($user, $balance, 'seed-'.uniqid());

        return [$tech, $user];
    }

    private function wallet(User $user): Wallet
    {
        return $user->wallet()->firstOrFail();
    }

    private function request(User $user, string $amount = '100.00'): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/technician/withdrawals', [
            'amount' => $amount,
            'method' => 'bank_account',
            'destination_details' => 'IBAN SY00 0000',
        ]);
    }

    public function test_requesting_reserves_the_amount_into_held(): void
    {
        [$tech, $user] = $this->fundedTech();

        $this->request($user, '100.00')->assertCreated();

        $wallet = $this->wallet($user);
        $this->assertSame(400.0, (float) $wallet->available_balance);
        $this->assertSame(100.0, (float) $wallet->held_balance);
        $this->assertDatabaseHas('withdrawals', [
            'technician_id' => $tech->id, 'status' => 'processing', 'amount' => '100.00',
        ]);
    }

    public function test_below_the_minimum_is_rejected(): void
    {
        [, $user] = $this->fundedTech();
        $this->request($user, '50.00')->assertStatus(409); // min is 100

        $this->assertSame(500.0, (float) $this->wallet($user)->available_balance);
    }

    public function test_more_than_the_available_balance_is_rejected(): void
    {
        [, $user] = $this->fundedTech('120.00');
        $this->request($user, '200.00')->assertStatus(409);
    }

    public function test_only_one_active_withdrawal_at_a_time(): void
    {
        [, $user] = $this->fundedTech();
        $this->request($user, '100.00')->assertCreated();
        $this->request($user, '100.00')->assertStatus(409);
    }

    public function test_an_open_dispute_blocks_a_withdrawal(): void
    {
        [$tech, $user] = $this->fundedTech();
        $order = Order::factory()->create(['technician_id' => $tech->id, 'status' => OrderStatus::InProgress]);
        DB::table('disputes')->insert([
            'order_id' => $order->id, 'raised_by' => $order->client_id,
            'reason' => 'quality', 'status' => 'open', 'resolved_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->request($user, '100.00')->assertStatus(409);
    }

    public function test_a_non_technician_cannot_request(): void
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);

        $this->request($client, '100.00')->assertForbidden();
    }

    public function test_admin_completing_pays_out_the_reserved_funds(): void
    {
        [$tech, $user] = $this->fundedTech();
        $id = $this->request($user, '100.00')->assertCreated()->json('data.id');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->post("/api/admin/withdrawals/{$id}/complete", [
            'receipt' => UploadedFile::fake()->image('paid.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $wallet = $this->wallet($user);
        $this->assertSame(400.0, (float) $wallet->available_balance);
        $this->assertSame(0.0, (float) $wallet->held_balance);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'completed', 'processed_by' => $admin->id]);
    }

    public function test_admin_rejecting_returns_the_reserved_funds(): void
    {
        [, $user] = $this->fundedTech();
        $id = $this->request($user, '100.00')->assertCreated()->json('data.id');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/withdrawals/{$id}/reject")->assertOk();

        $wallet = $this->wallet($user);
        $this->assertSame(500.0, (float) $wallet->available_balance);
        $this->assertSame(0.0, (float) $wallet->held_balance);
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'rejected']);
    }

    public function test_only_an_admin_can_complete(): void
    {
        [, $user] = $this->fundedTech();
        $id = $this->request($user, '100.00')->assertCreated()->json('data.id');

        $this->actingAs($user, 'sanctum')->post("/api/admin/withdrawals/{$id}/complete", [
            'receipt' => UploadedFile::fake()->image('x.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
