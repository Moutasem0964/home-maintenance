<?php

namespace Tests\Feature;

use App\Enums\DispatchOfferStatus;
use App\Enums\OrderStatus;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Services\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    private function availableTech(ServiceCategory $category, float $lat = 33.5, float $lng = 36.3): Technician
    {
        $tech = Technician::factory()->available()->create(['current_lat' => $lat, 'current_lng' => $lng]);
        $tech->services()->attach($category->id);

        return $tech;
    }

    /** @param array<string, mixed> $overrides */
    private function offer(Order $order, Technician $tech, array $overrides = []): DispatchOffer
    {
        return DispatchOffer::factory()->create(array_merge([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => DispatchOfferStatus::Offered,
            'expires_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_expires_a_timed_out_offer_and_reassigns_to_the_next_tech(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = Order::factory()->create([
            'service_category_id' => $category->id, 'lat' => 33.5, 'lng' => 36.3, 'status' => OrderStatus::Pending,
        ]);
        $first = $this->availableTech($category, 33.5, 36.3);
        $next = $this->availableTech($category, 33.6, 36.4);

        $offer = $this->offer($order, $first);

        $this->service()->expireStaleOffers();

        $this->assertSame(DispatchOfferStatus::Expired, $offer->refresh()->status);
        $this->assertDatabaseHas('dispatch_offers', [
            'order_id' => $order->id,
            'technician_id' => $next->id,
            'status' => 'offered',
        ]);
    }

    public function test_leaves_a_fresh_offer_untouched(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $tech = Technician::factory()->available()->create();
        $offer = $this->offer($order, $tech, ['expires_at' => now()->addMinutes(2)]);

        $this->service()->expireStaleOffers();

        $this->assertSame(DispatchOfferStatus::Offered, $offer->refresh()->status);
    }

    public function test_does_not_reassign_when_the_order_is_already_taken(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Accepted]);
        $tech = Technician::factory()->available()->create();
        $offer = $this->offer($order, $tech);

        $this->service()->expireStaleOffers();

        $this->assertSame(DispatchOfferStatus::Expired, $offer->refresh()->status);
        $this->assertSame(1, $order->dispatchOffers()->count()); // no new offer
    }

    public function test_expires_even_when_no_next_technician_exists(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $tech = Technician::factory()->available()->create();
        $offer = $this->offer($order, $tech);

        $this->service()->expireStaleOffers();

        $this->assertSame(DispatchOfferStatus::Expired, $offer->refresh()->status);
        $this->assertSame(1, $order->dispatchOffers()->count()); // pool exhausted, no reassign
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->artisan('dispatch:expire-offers')->assertSuccessful();
    }
}
