<?php

namespace Tests\Feature;

use App\Enums\BalanceType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWallet(): User
    {
        $user = User::factory()->verified()->create();
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    private function assertLedgerMatchesCache(Wallet $wallet): void
    {
        $wallet->refresh();
        $this->assertSame(
            (float) $wallet->available_balance,
            (float) $wallet->ledgerBalance(BalanceType::Available),
        );
    }

    public function test_wallet_requires_authentication(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();
    }

    public function test_show_returns_the_users_balances(): void
    {
        $user = $this->userWithWallet();

        $this->actingAs($user, 'sanctum')->getJson('/api/wallet')
            ->assertOk()
            ->assertJsonStructure(['data' => ['available_balance', 'held_balance', 'currency']]);
    }

    public function test_top_up_credits_available_and_writes_a_deposit_entry(): void
    {
        $user = $this->userWithWallet();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wallet/top-up', ['amount' => '50.00', 'gateway_reference' => 'gw-1'])
            ->assertOk()
            ->assertJsonPath('data.available_balance', '50.00');

        $wallet = $user->wallet()->firstOrFail();
        $this->assertSame(50.00, (float) $wallet->available_balance);
        $this->assertLedgerMatchesCache($wallet);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'deposit',
            'balance_type' => 'available',
        ]);
    }

    public function test_top_up_is_idempotent_on_gateway_reference(): void
    {
        $user = $this->userWithWallet();

        $payload = ['amount' => '50.00', 'gateway_reference' => 'gw-dup'];
        $this->actingAs($user, 'sanctum')->postJson('/api/wallet/top-up', $payload)->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/wallet/top-up', $payload)->assertOk();

        $wallet = $user->wallet()->firstOrFail();
        $this->assertSame(50.00, (float) $wallet->available_balance, 'a repeated webhook must not double-credit');
        $this->assertSame(1, $wallet->topUps()->count());
        $this->assertLedgerMatchesCache($wallet);
    }

    public function test_top_up_rejects_non_positive_amounts(): void
    {
        $user = $this->userWithWallet();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wallet/top-up', ['amount' => '0', 'gateway_reference' => 'gw-zero'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/wallet/top-up', ['amount' => '-5', 'gateway_reference' => 'gw-neg'])
            ->assertStatus(422);
    }
}
