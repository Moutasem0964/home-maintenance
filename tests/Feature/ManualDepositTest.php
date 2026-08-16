<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManualDepositTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
        Storage::fake('local');
    }

    private function client(): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    private function available(User $user): float
    {
        return (float) $user->wallet()->firstOrFail()->available_balance;
    }

    private function submit(User $user, string $amount = '100.00', string $reference = 'TX-1'): int
    {
        $id = $this->actingAs($user, 'sanctum')->post('/api/wallet/deposits', [
            'amount' => $amount,
            'reference' => $reference,
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return (int) $id;
    }

    public function test_a_request_is_recorded_pending_without_crediting(): void
    {
        $user = $this->client();
        $this->submit($user);

        $this->assertDatabaseHas('top_ups', ['gateway_reference' => 'TX-1', 'status' => 'pending']);
        $this->assertSame(0.0, $this->available($user));
    }

    public function test_the_same_transfer_reference_cannot_be_submitted_twice(): void
    {
        $user = $this->client();
        $this->submit($user, reference: 'TX-DUP');

        $this->actingAs($user, 'sanctum')->post('/api/wallet/deposits', [
            'amount' => '100.00',
            'reference' => 'TX-DUP',
            'receipt' => UploadedFile::fake()->image('r.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['reference']);
    }

    public function test_admin_approval_credits_the_wallet(): void
    {
        $user = $this->client();
        $id = $this->submit($user);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/deposits/{$id}/approve")->assertOk();

        $this->assertSame(100.0, $this->available($user));
        $this->assertDatabaseHas('top_ups', ['id' => $id, 'status' => 'succeeded', 'reviewed_by' => $admin->id]);
    }

    public function test_admin_can_correct_the_amount_to_the_receipt(): void
    {
        $user = $this->client();
        $id = $this->submit($user, amount: '100.00');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/deposits/{$id}/approve", ['amount' => '80.00'])->assertOk();

        $this->assertSame(80.0, $this->available($user));
    }

    public function test_a_top_up_cannot_be_approved_twice(): void
    {
        $user = $this->client();
        $id = $this->submit($user);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/deposits/{$id}/approve")->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/deposits/{$id}/approve")->assertStatus(409);

        $this->assertSame(100.0, $this->available($user)); // credited once only
    }

    public function test_rejection_credits_nothing(): void
    {
        $user = $this->client();
        $id = $this->submit($user);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/deposits/{$id}/reject")->assertOk();

        $this->assertSame(0.0, $this->available($user));
        $this->assertDatabaseHas('top_ups', ['id' => $id, 'status' => 'rejected']);
    }

    public function test_only_an_admin_can_approve(): void
    {
        $user = $this->client();
        $id = $this->submit($user);

        $this->actingAs($this->client(), 'sanctum')->postJson("/api/admin/deposits/{$id}/approve")->assertForbidden();
        $this->assertDatabaseHas('top_ups', ['id' => $id, 'status' => 'pending']);
    }
}
