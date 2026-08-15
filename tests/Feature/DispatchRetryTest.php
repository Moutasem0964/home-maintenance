<?php

namespace Tests\Feature;

use App\Enums\DispatchOfferStatus;
use App\Enums\OrderStatus;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Services\AssignmentService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchRetryTest extends TestCase
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

    private function pendingOrder(ServiceCategory $category): Order
    {
        return Order::factory()->create([
            'service_category_id' => $category->id,
            'lat' => 33.5,
            'lng' => 36.3,
            'status' => OrderStatus::Pending,
        ]);
    }

    private function availableTech(ServiceCategory $category, float $lat = 33.5, float $lng = 36.3): Technician
    {
        $tech = Technician::factory()->available()->create(['current_lat' => $lat, 'current_lng' => $lng]);
        $tech->services()->attach($category->id);

        return $tech;
    }

    public function test_re_offers_a_pending_order_that_has_no_live_offer(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category);

        $this->assertSame(1, $this->service()->retryPending());
        $this->assertDatabaseHas('dispatch_offers', [
            'order_id' => $order->id, 'technician_id' => $tech->id, 'status' => 'offered',
        ]);
    }

    public function test_re_offers_to_a_new_tech_after_a_prior_offer_expired_with_no_next_tech(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);

        // First tech is offered, then that offer times out with nobody else available yet.
        $first = $this->availableTech($category);
        $this->service()->offerToNext($order);
        $order->dispatchOffers()->update([
            'status' => DispatchOfferStatus::Expired, 'expires_at' => now()->subMinute(),
        ]);

        // A second tech comes online — the safety net should reach them.
        $second = $this->availableTech($category);

        $this->assertSame(1, $this->service()->retryPending());
        $this->assertDatabaseHas('dispatch_offers', [
            'order_id' => $order->id, 'technician_id' => $second->id, 'status' => 'offered',
        ]);
        // The first tech is never re-offered.
        $this->assertSame(1, $order->dispatchOffers()->where('technician_id', $first->id)->count());
    }

    public function test_skips_a_pending_order_that_already_has_a_live_offer(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $this->availableTech($category);

        $this->service()->offerToNext($order); // live offer exists

        $this->assertSame(0, $this->service()->retryPending());
        $this->assertSame(1, $order->dispatchOffers()->count());
    }

    public function test_ignores_orders_that_are_not_pending(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $order->update(['status' => OrderStatus::Accepted]);
        $this->availableTech($category);

        $this->assertSame(0, $this->service()->retryPending());
        $this->assertDatabaseCount('dispatch_offers', 0);
    }

    private function pastOffer(Order $order, Technician $tech, DispatchOfferStatus $status, int $attempts = 1): DispatchOffer
    {
        return DispatchOffer::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => $status,
            'attempts' => $attempts,
            'offered_at' => now()->subMinutes(2),
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function test_re_offers_a_timed_out_tech_when_no_fresh_tech_is_left(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category); // the only qualified tech
        $offer = $this->pastOffer($order, $tech, DispatchOfferStatus::Expired); // they missed the first offer

        $this->assertSame(1, $this->service()->retryPending());

        // The same row is reused (unique index): reopened and attempt count bumped.
        $this->assertSame(1, $order->dispatchOffers()->count());
        $offer->refresh();
        $this->assertSame(DispatchOfferStatus::Offered, $offer->status);
        $this->assertSame(2, $offer->attempts);
        $this->assertTrue($offer->expires_at->isFuture());
    }

    public function test_never_re_offers_a_tech_who_declined(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category);
        $offer = $this->pastOffer($order, $tech, DispatchOfferStatus::Rejected); // explicit "no"

        $this->assertSame(0, $this->service()->retryPending());
        $this->assertSame(DispatchOfferStatus::Rejected, $offer->refresh()->status);
    }

    public function test_stops_re_offering_after_the_attempt_cap(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = $this->pendingOrder($category);
        $tech = $this->availableTech($category);
        $offer = $this->pastOffer($order, $tech, DispatchOfferStatus::Expired, attempts: 3); // cap reached

        $this->assertSame(0, $this->service()->retryPending());
        $offer->refresh();
        $this->assertSame(DispatchOfferStatus::Expired, $offer->status);
        $this->assertSame(3, $offer->attempts);
    }
}
