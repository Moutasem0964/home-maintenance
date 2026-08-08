<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceCategoryLeafTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_rejects_a_parent_category_with_children(): void
    {
        $user = User::factory()->verified()->create();
        $parent = ServiceCategory::factory()->create();
        ServiceCategory::factory()->childOf($parent)->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'operation_id' => (string) Str::uuid(),
            'service_category_id' => $parent->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertStatus(422)->assertJsonValidationErrors(['service_category_id']);
    }

    public function test_order_accepts_a_leaf_category(): void
    {
        // A leaf (no children) clears the rule. Wallet isn't funded, so creation still
        // 422s on the fee — but crucially NOT on service_category_id, proving the rule passed.
        $user = User::factory()->verified()->create();
        $parent = ServiceCategory::factory()->create();
        $leaf = ServiceCategory::factory()->childOf($parent)->create();
        $address = Address::factory()->for($user)->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/orders', [
            'operation_id' => (string) Str::uuid(),
            'service_category_id' => $leaf->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertJsonMissingValidationErrors(['service_category_id']);
    }

    public function test_technician_set_services_rejects_a_parent_category(): void
    {
        $tech = Technician::factory()->create();
        $techUser = $tech->user()->firstOrFail();
        $parent = ServiceCategory::factory()->create();
        ServiceCategory::factory()->childOf($parent)->create();

        $this->actingAs($techUser, 'sanctum')->putJson('/api/technician/services', [
            'service_category_ids' => [$parent->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['service_category_ids.0']);
    }
}
