<?php

namespace Tests\Feature;

use App\Contracts\LocationTracker;
use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\DispatchOfferStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Appointment;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\Technician;
use App\Services\ArrivalService;
use App\Services\AssignmentService;
use App\Services\CancellationService;
use App\Services\SchedulingService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationTrackingTest extends TestCase
{
    use RefreshDatabase;

    private RecordingLocationTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
        $this->tracker = new RecordingLocationTracker;
        $this->app->instance(LocationTracker::class, $this->tracker);
    }

    public function test_accepting_an_urgent_offer_opens_live_tracking(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create(['type' => OrderType::Urgent, 'status' => OrderStatus::Pending]);
        $offer = DispatchOffer::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => DispatchOfferStatus::Offered,
            'offered_at' => now(),
            'expires_at' => now()->addMinutes(2),
        ]);

        app(AssignmentService::class)->accept($offer);

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertContains($order->id, $this->tracker->opened);
    }

    public function test_activating_a_scheduled_appointment_opens_live_tracking(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'type' => OrderType::Scheduled,
            'status' => OrderStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);
        Appointment::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'type' => AppointmentType::Inspection,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHours(2),
            'status' => AppointmentStatus::Confirmed,
        ]);

        app(SchedulingService::class)->activateDue();

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertContains($order->id, $this->tracker->opened);
    }

    public function test_a_warranty_activation_does_not_open_tracking(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'kind' => OrderKind::Warranty,
            'type' => OrderType::Scheduled,
            'status' => OrderStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);
        Appointment::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'type' => AppointmentType::Inspection,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHours(2),
            'status' => AppointmentStatus::Confirmed,
        ]);

        app(SchedulingService::class)->activateDue();

        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
        $this->assertNotContains($order->id, $this->tracker->opened);
    }

    public function test_marking_arrival_closes_live_tracking(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::Accepted,
            'lat' => '33.5138000',
            'lng' => '36.2765000',
        ]);

        app(ArrivalService::class)->markArrived($order, 33.5138, 36.2765);

        $this->assertContains($order->id, $this->tracker->closed);
    }

    public function test_a_technician_withdrawing_closes_live_tracking(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::Accepted,
        ]);

        app(CancellationService::class)->technicianWithdraw($order);

        $this->assertContains($order->id, $this->tracker->closed);
    }
}

/** In-memory tracker that records which orders had tracking opened/closed. */
class RecordingLocationTracker implements LocationTracker
{
    /** @var array<int, int> */
    public array $opened = [];

    /** @var array<int, int> */
    public array $closed = [];

    public function open(Order $order): void
    {
        $this->opened[] = $order->id;
    }

    public function close(Order $order): void
    {
        $this->closed[] = $order->id;
    }
}
