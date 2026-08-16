<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirebaseTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_token_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/firebase/token')->assertUnauthorized();
    }

    public function test_it_mints_a_custom_token_for_the_signed_in_user(): void
    {
        $user = User::factory()->create();

        // The default (log) driver returns a deterministic local token keyed by the user id.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/firebase/token')
            ->assertOk()
            ->assertJsonPath('token', 'local-custom-token:'.$user->id);
    }
}
