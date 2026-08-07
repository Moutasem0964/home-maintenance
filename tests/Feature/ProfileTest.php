<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_requires_authentication(): void
    {
        $this->putJson('/api/profile', ['name' => 'X'])->assertUnauthorized();
    }

    public function test_user_updates_their_name_and_avatar(): void
    {
        $user = User::factory()->verified()->create(['name' => 'Old Name']);

        $this->actingAs($user, 'sanctum')->putJson('/api/profile', [
            'name' => 'New Name',
            'profile_image_url' => 'https://cdn.example.com/me.jpg',
        ])->assertOk()->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'profile_image_url' => 'https://cdn.example.com/me.jpg',
        ]);
    }

    public function test_update_requires_a_name(): void
    {
        $user = User::factory()->verified()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
