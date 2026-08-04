<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: User, 2: Technician} */
    private function completedOrder(OrderStatus $status = OrderStatus::Completed): array
    {
        $client = User::factory()->create();
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => $status,
        ]);

        return [$order, $client, $tech];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'cleanliness' => 5,
            'quality' => 4,
            'price_rating' => 5,
            'comment' => 'Great work, on time.',
        ], $overrides);
    }

    public function test_review_requires_authentication(): void
    {
        [$order] = $this->completedOrder();

        $this->postJson("/api/orders/{$order->id}/review", $this->payload())->assertUnauthorized();
    }

    public function test_only_the_client_can_review(): void
    {
        [$order, , $tech] = $this->completedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload())->assertForbidden();
    }

    public function test_client_reviews_a_completed_order(): void
    {
        [$order, $client, $tech] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.quality', 4);

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'cleanliness' => 5,
            'price_rating' => 5,
        ]);
    }

    public function test_cannot_review_the_same_order_twice(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload())->assertCreated();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload())->assertStatus(409);
    }

    public function test_cannot_review_an_order_that_is_not_completed(): void
    {
        [$order, $client] = $this->completedOrder(OrderStatus::InProgress);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload())->assertStatus(409);
    }

    public function test_a_resolved_order_can_be_reviewed(): void
    {
        [$order, $client] = $this->completedOrder(OrderStatus::Resolved);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload())->assertCreated();
    }

    public function test_rejects_out_of_range_ratings(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload(['price_rating' => 6]))->assertStatus(422);
    }

    public function test_requires_all_three_ratings(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload(['quality' => null]))->assertStatus(422);
    }

    public function test_a_low_price_rating_sets_the_anomaly_flag(): void
    {
        [$order, $client] = $this->completedOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/review", $this->payload(['price_rating' => 1]))->assertCreated();

        $this->assertDatabaseHas('reviews', ['order_id' => $order->id, 'price_anomaly_flag' => true]);
    }
}
