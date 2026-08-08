<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\DispatchOfferStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentType;
use App\Models\Appointment;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\EscrowService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    /**
     * An order with a 50.00 inspection fee already held, at the given status.
     *
     * @return array{0: Order, 1: User, 2: Technician}
     */
    private function heldOrder(OrderStatus $status, array $overrides = []): array
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, '500.00', 'seed-'.uniqid());

        $tech = Technician::factory()->active()->create();
        Wallet::create(['user_id' => $tech->user_id]);

        $order = Order::factory()->create(array_merge([
            'client_id' => $client->id,
            'technician_id' => $status === OrderStatus::Pending ? null : $tech->id,
            'status' => OrderStatus::InProgress, // temporary, so holdFunds runs before we set the real status
            'commission_rate' => '0.1000',
            'inspection_fee' => '50.00',
        ], $overrides));

        app(EscrowService::class)->holdFunds($order, '50.00', PaymentType::Inspection, "insp:{$order->id}", "op:{$order->id}");
        $order->update(['status' => $status]);

        return [$order, $client, $tech];
    }

    // ---------- client cancel ----------

    public function test_cancel_requires_authentication(): void
    {
        [$order] = $this->heldOrder(OrderStatus::Pending);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertUnauthorized();
    }

    public function test_only_the_client_can_cancel(): void
    {
        [$order, , $tech] = $this->heldOrder(OrderStatus::Accepted);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")->assertForbidden();
    }

    public function test_cancelling_a_pending_order_refunds_the_full_inspection_fee(): void
    {
        [$order, $client] = $this->heldOrder(OrderStatus::Pending);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(OrderStatus::Canceled, $order->refresh()->status);
        $this->assertSame(500.0, (float) $client->wallet()->firstOrFail()->available_balance);
        $this->assertSame(0.0, (float) $client->wallet()->firstOrFail()->held_balance);
    }

    public function test_late_cancel_keeps_the_fee_share_for_the_tech_and_refunds_the_rest(): void
    {
        [$order, $client, $tech] = $this->heldOrder(OrderStatus::Accepted);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        // Not arrived → 50/50: 25 to the tech (− 10% commission = 22.50), 25 refunded to the client.
        $this->assertSame(OrderStatus::Canceled, $order->refresh()->status);
        $this->assertSame(475.0, (float) $client->wallet()->firstOrFail()->available_balance); // 450 + 25
        $this->assertSame(22.5, (float) $tech->user->wallet()->firstOrFail()->available_balance);
    }

    public function test_cancel_after_the_tech_arrives_releases_the_full_fee_to_the_tech(): void
    {
        [$order, $client, $tech] = $this->heldOrder(OrderStatus::Accepted, ['arrived_at' => now()]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        // Tech made the trip → recorded as a no-show; full 50 fee released (− 10% commission
        // = 45), client refunded nothing.
        $this->assertSame(OrderStatus::NoShow, $order->refresh()->status);
        $this->assertSame(450.0, (float) $client->wallet()->firstOrFail()->available_balance); // unchanged
        $this->assertSame(45.0, (float) $tech->user->wallet()->firstOrFail()->available_balance);
    }

    public function test_late_cancel_of_a_scheduled_order_frees_the_appointment(): void
    {
        [$order, $client, $tech] = $this->heldOrder(OrderStatus::Scheduled, [
            'type' => OrderType::Scheduled,
            'scheduled_at' => now()->addDay(),
        ]);
        $appt = Appointment::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'type' => AppointmentType::Inspection,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(120),
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame(AppointmentStatus::Canceled, $appt->refresh()->status);
    }

    public function test_cannot_cancel_an_in_progress_order(): void
    {
        [$order, $client] = $this->heldOrder(OrderStatus::InProgress);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/cancel")->assertStatus(409);
    }

    // ---------- technician withdraw-after-accept ----------

    public function test_only_the_assigned_tech_can_withdraw(): void
    {
        [$order] = $this->heldOrder(OrderStatus::Accepted);
        $other = Technician::factory()->active()->create();

        $this->actingAs($other->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/withdraw")->assertForbidden();
    }

    public function test_withdraw_returns_the_order_to_pending_and_re_dispatches(): void
    {
        $cat = ServiceCategory::factory()->create();
        [$order, , $tech] = $this->heldOrder(OrderStatus::Accepted, ['service_category_id' => $cat->id]);
        $tech->services()->attach($cat->id);

        // The withdrawing tech already had an accepted offer (so re-dispatch skips him)...
        DispatchOffer::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => DispatchOfferStatus::Accepted,
            'offered_at' => now(),
            'expires_at' => now()->subMinute(),
        ]);
        // ...and a second qualified tech is available to receive the re-offer.
        $other = Technician::factory()->active()->create(['is_available' => true, 'current_lat' => 33.5, 'current_lng' => 36.3]);
        $other->services()->attach($cat->id);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/withdraw")->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertNull($order->technician_id);
        $this->assertDatabaseHas('dispatch_offers', [
            'order_id' => $order->id,
            'technician_id' => $other->id,
            'status' => 'offered',
        ]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'technician_withdrew']);
    }

    public function test_cannot_withdraw_a_job_that_is_already_in_progress(): void
    {
        [$order, , $tech] = $this->heldOrder(OrderStatus::InProgress);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/withdraw")->assertStatus(409);
    }
}
