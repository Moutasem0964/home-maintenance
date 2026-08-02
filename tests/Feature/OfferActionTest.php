<?php

namespace Tests\Feature;

use App\Enums\DispatchOfferStatus;
use App\Enums\OrderStatus;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferActionTest extends TestCase
{
    use RefreshDatabase;

    private function tech(array $overrides = []): Technician
    {
        return Technician::factory()->available()->create($overrides);
    }

    private function userOf(Technician $tech): User
    {
        return $tech->user()->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function offerFor(Technician $tech, Order $order, array $overrides = []): DispatchOffer
    {
        return DispatchOffer::factory()->create(array_merge([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => DispatchOfferStatus::Offered,
            'expires_at' => now()->addMinutes(2),
        ], $overrides));
    }

    // ---------- index ----------

    public function test_offers_index_requires_a_technician(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/technician/offers')->assertForbidden();
    }

    public function test_technician_sees_only_open_unexpired_offers(): void
    {
        $tech = $this->tech();
        $open = $this->offerFor($tech, Order::factory()->create());
        $this->offerFor($tech, Order::factory()->create(), ['status' => DispatchOfferStatus::Rejected]);
        $this->offerFor($tech, Order::factory()->create(), ['expires_at' => now()->subMinute()]);

        $this->actingAs($this->userOf($tech), 'sanctum')->getJson('/api/technician/offers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    // ---------- accept ----------

    public function test_accept_requires_authentication(): void
    {
        $offer = $this->offerFor($this->tech(), Order::factory()->create());

        $this->postJson("/api/technician/offers/{$offer->id}/accept")->assertUnauthorized();
    }

    public function test_a_non_technician_cannot_accept(): void
    {
        $offer = $this->offerFor($this->tech(), Order::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/accept")->assertForbidden();
    }

    public function test_cannot_accept_another_technicians_offer(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $mine = $this->tech();
        $offerOfOther = $this->offerFor($this->tech(), $order);

        $this->actingAs($this->userOf($mine), 'sanctum')
            ->postJson("/api/technician/offers/{$offerOfOther->id}/accept")->assertNotFound();
    }

    public function test_technician_accepts_and_is_assigned_the_order(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $tech = $this->tech();
        $offer = $this->offerFor($tech, $order);

        $this->actingAs($this->userOf($tech), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $order->refresh();
        $this->assertSame(OrderStatus::Accepted, $order->status);
        $this->assertSame($tech->id, $order->technician_id);
        $this->assertSame(DispatchOfferStatus::Accepted, $offer->refresh()->status);
    }

    public function test_accepting_expires_the_other_open_offers(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $winner = $this->tech();
        $loser = $this->tech();
        $winningOffer = $this->offerFor($winner, $order);
        $losingOffer = $this->offerFor($loser, $order);

        $this->actingAs($this->userOf($winner), 'sanctum')
            ->postJson("/api/technician/offers/{$winningOffer->id}/accept")->assertOk();

        $this->assertSame(DispatchOfferStatus::Expired, $losingOffer->refresh()->status);
    }

    public function test_cannot_accept_an_already_taken_order(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Accepted]);
        $tech = $this->tech();
        $offer = $this->offerFor($tech, $order);

        $this->actingAs($this->userOf($tech), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/accept")->assertStatus(409);
    }

    public function test_cannot_accept_an_expired_offer(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $tech = $this->tech();
        $offer = $this->offerFor($tech, $order, ['expires_at' => now()->subMinute()]);

        $this->actingAs($this->userOf($tech), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/accept")->assertStatus(409);
    }

    // ---------- decline ----------

    public function test_declining_reassigns_to_the_next_technician(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = Order::factory()->create([
            'service_category_id' => $category->id,
            'lat' => 33.5, 'lng' => 36.3, 'status' => OrderStatus::Pending,
        ]);
        $first = $this->tech(['current_lat' => 33.5, 'current_lng' => 36.3]);
        $first->services()->attach($category->id);
        $next = $this->tech(['current_lat' => 33.6, 'current_lng' => 36.4]);
        $next->services()->attach($category->id);

        $offer = $this->offerFor($first, $order);

        $this->actingAs($this->userOf($first), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/decline", ['reason' => 'busy'])
            ->assertOk();

        $this->assertSame(DispatchOfferStatus::Rejected, $offer->refresh()->status);
        $this->assertDatabaseHas('dispatch_offers', [
            'order_id' => $order->id,
            'technician_id' => $next->id,
            'status' => 'offered',
        ]);
    }

    public function test_cannot_decline_a_closed_offer(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $tech = $this->tech();
        $offer = $this->offerFor($tech, $order, ['status' => DispatchOfferStatus::Rejected]);

        $this->actingAs($this->userOf($tech), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/decline")->assertStatus(409);
    }

    public function test_cannot_decline_an_expired_offer(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $tech = $this->tech();
        $offer = $this->offerFor($tech, $order, ['expires_at' => now()->subMinute()]);

        $this->actingAs($this->userOf($tech), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/decline")->assertStatus(409);
    }
}
