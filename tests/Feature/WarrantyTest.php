<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClosureService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarrantyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    /** @return array{0: Order, 1: User, 2: Technician} */
    private function order(OrderStatus $status, array $overrides = []): array
    {
        $client = User::factory()->create();
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create(array_merge([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => $status,
        ], $overrides));

        return [$order, $client, $tech];
    }

    private function approvedQuote(Order $order, int $warrantyDays): Quote
    {
        return Quote::create([
            'order_id' => $order->id,
            'technician_id' => $order->technician_id,
            'type' => QuoteType::Initial,
            'labor_cost' => '80.00',
            'warranty_days' => $warrantyDays,
            'status' => QuoteStatus::Approved,
            'expires_at' => now()->addDay(),
        ]);
    }

    // ---------- warranty stamp at completion ----------

    public function test_auto_completion_stamps_warranty_from_the_approved_quote(): void
    {
        [$order] = $this->order(OrderStatus::InProgress, ['closure_expires_at' => now()->subMinute()]);
        $this->approvedQuote($order, 30);

        app(ClosureService::class)->autoCompleteStaleClosures();

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->warranty_until);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $order->warranty_until->timestamp, 5);
    }

    public function test_code_verification_stamps_warranty(): void
    {
        [$order] = $this->order(OrderStatus::InProgress);
        $order->closure_code = '1234';
        $order->closure_expires_at = now()->addMinutes(10);
        $order->save();
        $this->approvedQuote($order, 14);

        app(ClosureService::class)->verify($order, '1234');

        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $order->refresh()->warranty_until->timestamp, 5);
    }

    public function test_completion_without_warranty_days_leaves_warranty_null(): void
    {
        [$order] = $this->order(OrderStatus::InProgress, ['closure_expires_at' => now()->subMinute()]);
        $this->approvedQuote($order, 0);

        app(ClosureService::class)->autoCompleteStaleClosures();

        $this->assertNull($order->refresh()->warranty_until);
    }

    // ---------- warranty claim ----------

    public function test_claim_requires_authentication(): void
    {
        [$order] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->postJson("/api/orders/{$order->id}/warranty-claim")->assertUnauthorized();
    }

    public function test_only_the_client_can_claim(): void
    {
        [$order, , $tech] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim")->assertForbidden();
    }

    public function test_client_claim_spawns_a_same_tech_zero_labor_warranty_order(): void
    {
        [$order, $client, $tech] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['description' => 'The leak came back.'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'warranty');

        $this->assertDatabaseHas('orders', [
            'parent_order_id' => $order->id,
            'kind' => 'warranty',
            'technician_id' => $tech->id,
            'client_id' => $client->id,
            'status' => 'in_progress',
            'inspection_fee' => '0.00',
        ]);
    }

    public function test_cannot_claim_after_the_warranty_expires(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->subDay()]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim")->assertStatus(409);
    }

    public function test_cannot_claim_without_a_warranty(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => null]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim")->assertStatus(409);
    }

    public function test_cannot_claim_twice(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim")->assertCreated();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim")->assertStatus(409);
    }
}
