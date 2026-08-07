<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeTest extends TestCase
{
    use RefreshDatabase;

    public function test_offices_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/offices')->assertUnauthorized();
    }

    public function test_lists_active_offices_and_excludes_inactive(): void
    {
        Office::create(['name' => 'مكتب المزة', 'address' => 'مزة شرقية، شارع الفارابي', 'phone' => '0912345678', 'is_active' => true]);
        Office::create(['name' => 'Closed Office', 'address' => 'Somewhere', 'phone' => '0900000000', 'is_active' => false]);

        $user = User::factory()->verified()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/offices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مكتب المزة')
            ->assertJsonStructure(['data' => [['id', 'name', 'address', 'phone']]]);
    }
}
