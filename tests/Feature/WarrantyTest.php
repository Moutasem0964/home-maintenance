<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClosureService;
use App\Services\SchedulingService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /** A valid future revisit time within the scheduling window. */
    private function when(): Carbon
    {
        return now()->addDay()->startOfHour();
    }

    public function test_claim_requires_authentication(): void
    {
        [$order] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->postJson("/api/orders/{$order->id}/warranty-claim")->assertUnauthorized();
    }

    public function test_only_the_client_can_claim(): void
    {
        [$order, , $tech] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $this->when()->toDateTimeString()])
            ->assertForbidden();
    }

    public function test_claim_requires_a_revisit_time(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['description' => 'no time given'])
            ->assertStatus(422);
    }

    public function test_client_claim_books_a_scheduled_same_tech_warranty_visit(): void
    {
        [$order, $client, $tech] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);
        $when = $this->when();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", [
                'scheduled_at' => $when->toDateTimeString(),
                'description' => 'The leak came back.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'warranty');

        $warranty = Order::where('parent_order_id', $order->id)->firstOrFail();
        $this->assertDatabaseHas('orders', [
            'id' => $warranty->id,
            'kind' => 'warranty',
            'technician_id' => $tech->id,
            'status' => 'scheduled',
            'inspection_fee' => '0.00',
        ]);
        // The original tech is booked into the client's chosen slot.
        $this->assertDatabaseHas('appointments', [
            'order_id' => $warranty->id, 'technician_id' => $tech->id, 'status' => 'confirmed',
        ]);
    }

    public function test_claim_is_rejected_when_the_tech_is_busy_at_that_time(): void
    {
        [$order, $client, $tech] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);
        $when = $this->when();

        // Pre-book the tech into the exact slot.
        Appointment::create([
            'order_id' => Order::factory()->create(['technician_id' => $tech->id])->id,
            'technician_id' => $tech->id,
            'type' => AppointmentType::Inspection,
            'starts_at' => $when,
            'ends_at' => $when->copy()->addHours(2),
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $when->toDateTimeString()])
            ->assertStatus(409);

        $this->assertDatabaseMissing('orders', ['parent_order_id' => $order->id, 'kind' => 'warranty']);
    }

    public function test_activating_a_warranty_appointment_puts_the_order_in_progress(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);
        $when = $this->when();
        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $when->toDateTimeString()])->assertCreated();

        $warranty = Order::where('parent_order_id', $order->id)->firstOrFail();

        $this->travelTo($when->copy()->addMinute());
        app(SchedulingService::class)->activateDue();

        $this->assertSame(OrderStatus::InProgress, $warranty->refresh()->status);
    }

    public function test_cannot_claim_after_the_warranty_expires(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->subDay()]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $this->when()->toDateTimeString()])
            ->assertStatus(409);
    }

    public function test_cannot_claim_without_a_warranty(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => null]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $this->when()->toDateTimeString()])
            ->assertStatus(409);
    }

    public function test_cannot_claim_twice(): void
    {
        [$order, $client] = $this->order(OrderStatus::Completed, ['warranty_until' => now()->addDays(10)]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $this->when()->toDateTimeString()])
            ->assertCreated();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/warranty-claim", ['scheduled_at' => $this->when()->addHour()->toDateTimeString()])
            ->assertStatus(409);
    }
}
