<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AssignmentService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    private function service(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    private function pendingOrder(ServiceCategory $category, float $lat = 33.5, float $lng = 36.3): Order
    {
        return Order::factory()->create([
            'service_category_id' => $category->id,
            'lat' => $lat,
            'lng' => $lng,
            'status' => OrderStatus::Pending,
        ]);
    }

    private function availableTech(ServiceCategory $category, float $lat = 33.5, float $lng = 36.3): Technician
    {
        $tech = Technician::factory()->available()->create(['current_lat' => $lat, 'current_lng' => $lng]);
        $tech->services()->attach($category->id);

        return $tech;
    }

    public function test_offers_the_order_to_a_qualified_available_technician(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category);

        $offer = $this->service()->offerToNext($order);

        $this->assertNotNull($offer);
        $this->assertDatabaseHas('dispatch_offers', [
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => 'offered',
        ]);
    }

    public function test_offers_to_the_nearest_technician(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category, 33.5, 36.3);
        $near = $this->availableTech($category, 33.5, 36.3);
        $this->availableTech($category, 10.0, 10.0); // far away

        $this->service()->offerToNext($order);

        $this->assertDatabaseHas('dispatch_offers', ['order_id' => $order->id, 'technician_id' => $near->id]);
        $this->assertSame(1, $order->dispatchOffers()->count());
    }

    public function test_skips_technicians_that_do_not_qualify(): void
    {
        $category = ServiceCategory::factory()->create();
        $other = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);

        // pending (not yet approved)
        $pending = Technician::factory()->create(['is_available' => true, 'current_lat' => 33.5, 'current_lng' => 36.3]);
        $pending->services()->attach($category->id);
        // approved but offline
        $offline = Technician::factory()->active()->create(['is_available' => false, 'current_lat' => 33.5, 'current_lng' => 36.3]);
        $offline->services()->attach($category->id);
        // approved + available but wrong category
        $this->availableTech($other);
        // approved + available + right category but no location
        $noLocation = Technician::factory()->active()->create(['is_available' => true, 'current_lat' => null, 'current_lng' => null]);
        $noLocation->services()->attach($category->id);

        $this->assertNull($this->service()->offerToNext($order));
        $this->assertDatabaseCount('dispatch_offers', 0);
    }

    public function test_no_offer_when_nobody_qualifies(): void
    {
        $order = $this->pendingOrder(ServiceCategory::factory()->create());

        $this->assertNull($this->service()->offerToNext($order));
    }

    public function test_never_offers_the_same_technician_twice(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $this->availableTech($category);

        $this->assertNotNull($this->service()->offerToNext($order));
        $this->assertNull($this->service()->offerToNext($order)); // the only tech was already offered
        $this->assertSame(1, $order->dispatchOffers()->count());
    }

    public function test_creating_an_order_via_the_api_dispatches_it(): void
    {
        $category = ServiceCategory::factory()->create();

        $client = User::factory()->verified()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, '100.00', 'seed-'.$client->id);
        $address = Address::factory()->for($client)->create(['lat' => 33.5, 'lng' => 36.3]);

        $tech = $this->availableTech($category, 33.5, 36.3);

        $this->actingAs($client, 'sanctum')->postJson('/api/orders', [
            'operation_id' => (string) Str::uuid(),
            'service_category_id' => $category->id,
            'address_id' => $address->id,
            'type' => 'urgent',
        ])->assertCreated();

        $this->assertDatabaseHas('dispatch_offers', ['technician_id' => $tech->id, 'status' => 'offered']);
    }
}
