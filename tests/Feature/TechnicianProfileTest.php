<?php

namespace Tests\Feature;

use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianProfileTest extends TestCase
{
    use RefreshDatabase;

    /** A user who owns a (pending) technician profile. */
    private function technicianUser(): User
    {
        return Technician::factory()->create()->user()->firstOrFail();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/technician/me')->assertUnauthorized();
    }

    public function test_a_non_technician_is_forbidden(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client, 'sanctum')->getJson('/api/technician/me')->assertForbidden();
    }

    public function test_technician_can_view_own_profile(): void
    {
        $user = $this->technicianUser();

        $this->actingAs($user, 'sanctum')->getJson('/api/technician/me')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_technician_can_set_services(): void
    {
        $user = $this->technicianUser();
        $a = ServiceCategory::factory()->create();
        $b = ServiceCategory::factory()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/technician/services', [
            'service_category_ids' => [$a->id, $b->id],
        ])->assertOk();

        $services = $user->technician()->firstOrFail()->services->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $services);
    }

    public function test_set_services_rejects_inactive_or_unknown_categories(): void
    {
        $user = $this->technicianUser();
        $inactive = ServiceCategory::factory()->inactive()->create();

        $this->actingAs($user, 'sanctum')->putJson('/api/technician/services', [
            'service_category_ids' => [$inactive->id, 999999],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['service_category_ids.0', 'service_category_ids.1']);
    }

    public function test_technician_can_go_available_with_a_location(): void
    {
        $user = $this->technicianUser();

        $this->actingAs($user, 'sanctum')->putJson('/api/technician/availability', [
            'is_available' => true,
            'current_lat' => 33.5138,
            'current_lng' => 36.2765,
        ])->assertOk()->assertJsonPath('data.is_available', true);

        $this->assertTrue((bool) $user->technician()->firstOrFail()->is_available);
    }

    public function test_going_available_requires_a_location(): void
    {
        $user = $this->technicianUser();

        $this->actingAs($user, 'sanctum')->putJson('/api/technician/availability', [
            'is_available' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['current_lat', 'current_lng']);
    }

    public function test_technician_can_go_offline(): void
    {
        $user = $this->technicianUser();

        $this->actingAs($user, 'sanctum')->putJson('/api/technician/availability', [
            'is_available' => false,
        ])->assertOk()->assertJsonPath('data.is_available', false);
    }
}
