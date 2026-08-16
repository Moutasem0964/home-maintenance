<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_requires_authentication(): void
    {
        $this->deleteJson('/api/auth/account', ['password' => 'x'])->assertUnauthorized();
    }

    public function test_delete_requires_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/account', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->assertNotSoftDeleted($user);
    }

    public function test_delete_soft_deletes_the_account_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);
        $token = $user->createToken('device')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/auth/account', ['password' => 'Password123'])
            ->assertOk();

        $this->assertSoftDeleted($user);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_departing_technician_is_taken_offline(): void
    {
        $tech = Technician::factory()->available()->create();
        /** @var User $user */
        $user = $tech->user;
        $user->update(['password' => 'Password123']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/auth/account', ['password' => 'Password123'])
            ->assertOk();

        $this->assertFalse((bool) $tech->refresh()->is_available);
    }
}
