<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Services\AssignmentService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class); // location_ttl_minutes = 10
    }

    // ---------- the heartbeat endpoint ----------

    public function test_a_technician_can_ping_its_location(): void
    {
        $tech = Technician::factory()->active()->create(['current_lat' => 33.5, 'current_lng' => 36.3]);

        $this->actingAs($tech->user, 'sanctum')
            ->patchJson('/api/technician/location', ['current_lat' => 33.6, 'current_lng' => 36.4])
            ->assertOk();

        $tech->refresh();
        $this->assertSame(33.6, (float) $tech->current_lat);
        $this->assertSame(36.4, (float) $tech->current_lng);
        $this->assertNotNull($tech->location_updated_at);
    }

    public function test_a_non_technician_cannot_ping(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->patchJson('/api/technician/location', ['current_lat' => 33.6, 'current_lng' => 36.4])
            ->assertForbidden();
    }

    public function test_the_ping_requires_valid_coordinates(): void
    {
        $tech = Technician::factory()->active()->create();

        $this->actingAs($tech->user, 'sanctum')
            ->patchJson('/api/technician/location', ['current_lat' => 999, 'current_lng' => 36.4])
            ->assertStatus(422)->assertJsonValidationErrors(['current_lat']);
    }

    // ---------- the dispatch freshness guard ----------

    private function pendingOrder(ServiceCategory $category): Order
    {
        return Order::factory()->create([
            'service_category_id' => $category->id,
            'lat' => 33.5, 'lng' => 36.3, 'status' => OrderStatus::Pending,
        ]);
    }

    private function availableTech(ServiceCategory $category): Technician
    {
        $tech = Technician::factory()->available()->create();
        $tech->services()->attach($category->id);

        return $tech;
    }

    public function test_a_technician_with_a_fresh_fix_is_dispatched(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category); // factory stamps location_updated_at = now()

        $offer = app(AssignmentService::class)->offerToNext($order);

        $this->assertNotNull($offer);
        $this->assertSame($tech->id, $offer->technician_id);
    }

    public function test_a_technician_with_a_stale_fix_is_skipped(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category);
        // Went online, then their location stopped updating 30 minutes ago (TTL is 10).
        $tech->update(['location_updated_at' => now()->subMinutes(30)]);

        $offer = app(AssignmentService::class)->offerToNext($order);

        $this->assertNull($offer);
    }
}
