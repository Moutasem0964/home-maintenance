<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Home',
            'lat' => 33.5138,
            'lng' => 36.2765,
            'building_no' => '12',
            'floor' => '3',
            'notes' => 'Near the pharmacy',
        ], $overrides);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/addresses')->assertUnauthorized();
    }

    public function test_index_returns_only_my_addresses(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Address::factory()->count(2)->for($me)->create();
        Address::factory()->for($other)->create();

        $this->actingAs($me, 'sanctum')->getJson('/api/addresses')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_store_creates_address_owned_by_me(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me, 'sanctum')->postJson('/api/addresses', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.label', 'Home');

        $this->assertDatabaseHas('addresses', ['user_id' => $me->id, 'label' => 'Home']);
    }

    public function test_store_requires_label_lat_lng(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me, 'sanctum')->postJson('/api/addresses', ['notes' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['label', 'lat', 'lng']);
    }

    public function test_rejects_out_of_range_lat_lng(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me, 'sanctum')
            ->postJson('/api/addresses', $this->payload(['lat' => 200, 'lng' => 999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lat', 'lng']);
    }

    public function test_update_changes_my_address(): void
    {
        $me = User::factory()->create();
        $address = Address::factory()->for($me)->create(['label' => 'Home']);

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/addresses/{$address->id}", $this->payload(['label' => 'Work']))
            ->assertOk()
            ->assertJsonPath('data.label', 'Work');

        $this->assertDatabaseHas('addresses', ['id' => $address->id, 'label' => 'Work']);
    }

    public function test_destroy_removes_my_address(): void
    {
        $me = User::factory()->create();
        $address = Address::factory()->for($me)->create();

        $this->actingAs($me, 'sanctum')
            ->deleteJson("/api/addresses/{$address->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_cannot_view_another_users_address(): void
    {
        $me = User::factory()->create();
        $address = Address::factory()->create();

        $this->actingAs($me, 'sanctum')
            ->getJson("/api/addresses/{$address->id}")
            ->assertNotFound();
    }

    public function test_cannot_update_another_users_address(): void
    {
        $me = User::factory()->create();
        $address = Address::factory()->create();

        $this->actingAs($me, 'sanctum')
            ->putJson("/api/addresses/{$address->id}", $this->payload())
            ->assertNotFound();
    }

    public function test_cannot_delete_another_users_address(): void
    {
        $me = User::factory()->create();
        $address = Address::factory()->create();

        $this->actingAs($me, 'sanctum')
            ->deleteJson("/api/addresses/{$address->id}")
            ->assertNotFound();
    }
}
