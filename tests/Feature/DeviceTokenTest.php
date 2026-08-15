<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_a_token_saves_it_for_the_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'abc123', 'platform' => 'android'])
            ->assertCreated();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id, 'token' => 'abc123', 'platform' => 'android',
        ]);
    }

    public function test_reregistering_the_same_token_updates_without_duplicating(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/device-tokens', ['token' => 'abc', 'platform' => 'android'])->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/device-tokens', ['token' => 'abc', 'platform' => 'ios'])->assertCreated();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', ['token' => 'abc', 'platform' => 'ios']);
    }

    public function test_a_token_moves_to_the_new_user_on_a_shared_device(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a, 'sanctum')->postJson('/api/device-tokens', ['token' => 'shared', 'platform' => 'android'])->assertCreated();
        $this->actingAs($b, 'sanctum')->postJson('/api/device-tokens', ['token' => 'shared', 'platform' => 'android'])->assertCreated();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', ['token' => 'shared', 'user_id' => $b->id]);
    }

    public function test_deleting_a_token_removes_it(): void
    {
        $user = User::factory()->create();
        DeviceToken::create(['user_id' => $user->id, 'token' => 'del', 'platform' => 'android']);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/device-tokens', ['token' => 'del'])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => 'del']);
    }

    public function test_deleting_only_affects_your_own_token(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        DeviceToken::create(['user_id' => $a->id, 'token' => 'mine', 'platform' => 'android']);

        $this->actingAs($b, 'sanctum')->deleteJson('/api/device-tokens', ['token' => 'mine'])->assertOk();

        $this->assertDatabaseHas('device_tokens', ['token' => 'mine', 'user_id' => $a->id]);
    }

    public function test_validation_rejects_a_bad_platform(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'x', 'platform' => 'windows'])
            ->assertStatus(422);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->postJson('/api/device-tokens', ['token' => 'x', 'platform' => 'android'])->assertUnauthorized();
        $this->deleteJson('/api/device-tokens', ['token' => 'x'])->assertUnauthorized();
    }
}
