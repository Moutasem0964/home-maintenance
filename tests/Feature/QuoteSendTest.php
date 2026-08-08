<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    /** @return array{0: Order, 1: Technician} */
    private function acceptedOrder(?float $guidePrice = 100.0): array
    {
        $category = ServiceCategory::factory()->create(['guide_price' => $guidePrice]);
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'service_category_id' => $category->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::Accepted,
            'arrived_at' => now(), // tech is on-site; quoting is unlocked
        ]);

        return [$order, $tech];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'labor_cost' => '80.00',
            'warranty_days' => 30,
            'parts' => [
                ['name' => 'Valve', 'price' => '20.00', 'classification' => 'standard', 'image_url' => 'https://example.com/p.jpg'],
            ],
        ], $overrides);
    }

    public function test_sending_a_quote_requires_authentication(): void
    {
        [$order] = $this->acceptedOrder();

        $this->postJson("/api/orders/{$order->id}/quotes", $this->payload())->assertUnauthorized();
    }

    public function test_cannot_send_a_quote_before_marking_arrival(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        $order->update(['arrived_at' => null]); // accepted but not yet on-site

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload())
            ->assertStatus(409);
    }

    public function test_only_the_assigned_technician_can_send_a_quote(): void
    {
        [$order] = $this->acceptedOrder();
        $otherTech = Technician::factory()->active()->create();

        $this->actingAs($otherTech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload())->assertForbidden();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload())->assertForbidden();
    }

    public function test_cannot_quote_an_order_that_is_not_accepted(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        $order->update(['status' => OrderStatus::InProgress]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload())->assertStatus(409);
    }

    public function test_technician_sends_an_initial_quote_with_parts(): void
    {
        [$order, $tech] = $this->acceptedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'initial')
            ->assertJsonPath('data.total', '100.00');

        $this->assertDatabaseHas('quotes', ['order_id' => $order->id, 'technician_id' => $tech->id, 'status' => 'pending']);
        $this->assertDatabaseHas('quote_parts', ['name' => 'Valve', 'classification' => 'standard']);
    }

    public function test_cannot_send_a_second_pending_quote(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        Quote::factory()->create(['order_id' => $order->id, 'technician_id' => $tech->id, 'status' => QuoteStatus::Pending]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload())->assertStatus(409);
    }

    public function test_a_price_anomaly_requires_a_justification(): void
    {
        [$order, $tech] = $this->acceptedOrder(100.0); // guide 100 × 2.0 → threshold 200

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload(['labor_cost' => '500.00', 'parts' => []]))
            ->assertStatus(422)->assertJsonValidationErrors(['justification']);
    }

    public function test_an_anomalous_quote_passes_with_a_justification(): void
    {
        [$order, $tech] = $this->acceptedOrder(100.0);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload([
                'labor_cost' => '500.00', 'parts' => [], 'justification' => 'Rare part, full-day job.',
            ]))->assertCreated();
    }

    public function test_each_part_requires_an_image(): void
    {
        [$order, $tech] = $this->acceptedOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/quotes", $this->payload([
                'parts' => [['name' => 'Valve', 'price' => '20.00', 'classification' => 'standard']],
            ]))->assertStatus(422)->assertJsonValidationErrors(['parts.0.image_url']);
    }
}
