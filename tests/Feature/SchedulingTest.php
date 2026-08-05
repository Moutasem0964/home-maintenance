<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\DispatchOfferStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\OfferUnavailableException;
use App\Models\Appointment;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\SchedulingService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    private function assignment(): AssignmentService
    {
        return app(AssignmentService::class);
    }

    private function scheduling(): SchedulingService
    {
        return app(SchedulingService::class);
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::factory()->create();
    }

    /** A qualified, active technician who serves the given category. */
    private function qualifiedTech(ServiceCategory $category): Technician
    {
        $tech = Technician::factory()->active()->create([
            'is_available' => true,
            'current_lat' => 33.5,
            'current_lng' => 36.3,
        ]);
        $tech->services()->attach($category->id);

        return $tech;
    }

    private function scheduledOrder(ServiceCategory $category, Carbon $at): Order
    {
        return Order::factory()->create([
            'client_id' => User::factory()->create()->id,
            'service_category_id' => $category->id,
            'type' => OrderType::Scheduled,
            'scheduled_at' => $at,
            'status' => OrderStatus::Pending,
        ]);
    }

    private function confirmedAppointment(Technician $tech, Order $order, Carbon $start): Appointment
    {
        return Appointment::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'type' => AppointmentType::Inspection,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(120),
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    // ---------- scheduled dispatch ----------

    public function test_scheduled_order_is_offered_to_a_conflict_free_qualified_tech(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $order = $this->scheduledOrder($cat, now()->addDay());

        $offer = $this->assignment()->offerToNext($order);

        $this->assertNotNull($offer);
        $this->assertSame($tech->id, $offer->technician_id);
    }

    public function test_scheduled_dispatch_skips_a_tech_booked_at_that_time(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $slot = now()->addDay()->startOfHour();

        // The only qualified tech is already booked for an overlapping slot.
        $this->confirmedAppointment($tech, $this->scheduledOrder($cat, $slot), $slot);

        $order = $this->scheduledOrder($cat, $slot->copy()->addMinutes(30)); // overlaps the 2h appointment

        $this->assertNull($this->assignment()->offerToNext($order));
    }

    // ---------- accept books an appointment ----------

    public function test_accepting_a_scheduled_order_books_a_confirmed_appointment(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $slot = now()->addDay()->startOfHour();
        $order = $this->scheduledOrder($cat, $slot);

        $offer = $this->assignment()->offerToNext($order);
        $accepted = $this->assignment()->accept($offer);

        $this->assertSame(OrderStatus::Scheduled, $accepted->status); // not Accepted yet — waits for activation
        $this->assertSame($tech->id, $accepted->technician_id);
        $this->assertDatabaseHas('appointments', [
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => 'confirmed',
            'type' => 'inspection',
        ]);
    }

    public function test_double_booking_the_same_slot_is_rejected(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $slot = now()->addDay()->startOfHour();

        $this->confirmedAppointment($tech, $this->scheduledOrder($cat, $slot), $slot);

        // Force an offer to the already-booked tech and try to accept it.
        $order = $this->scheduledOrder($cat, $slot);
        $offer = DispatchOffer::create([
            'order_id' => $order->id,
            'technician_id' => $tech->id,
            'status' => DispatchOfferStatus::Offered,
            'offered_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->expectException(OfferUnavailableException::class);
        $this->assignment()->accept($offer);
    }

    // ---------- activation cron ----------

    public function test_activate_due_activates_the_appointment_and_moves_the_order_to_accepted(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $order = $this->scheduledOrder($cat, now()->subMinute());
        $order->update(['technician_id' => $tech->id, 'status' => OrderStatus::Scheduled]);
        $this->confirmedAppointment($tech, $order, now()->subMinute());

        $this->assertSame(1, $this->scheduling()->activateDue());

        $this->assertSame(OrderStatus::Accepted, $order->refresh()->status);
        $this->assertDatabaseHas('appointments', ['order_id' => $order->id, 'status' => 'activated']);
    }

    public function test_activate_leaves_future_appointments_alone(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $order = $this->scheduledOrder($cat, now()->addDay());
        $order->update(['technician_id' => $tech->id, 'status' => OrderStatus::Scheduled]);
        $this->confirmedAppointment($tech, $order, now()->addDay());

        $this->assertSame(0, $this->scheduling()->activateDue());
        $this->assertSame(OrderStatus::Scheduled, $order->refresh()->status);
    }

    public function test_the_activate_command_runs(): void
    {
        $this->artisan('appointments:activate-due')->assertSuccessful();
    }

    // ---------- reminder cron ----------

    public function test_remind_stamps_upcoming_appointments_once(): void
    {
        $cat = $this->category();
        $tech = $this->qualifiedTech($cat);
        $order = $this->scheduledOrder($cat, now()->addMinutes(30)); // within the 60-min lead
        $order->update(['technician_id' => $tech->id, 'status' => OrderStatus::Scheduled]);
        $appt = $this->confirmedAppointment($tech, $order, now()->addMinutes(30));

        $this->assertSame(1, $this->scheduling()->remindUpcoming());
        $this->assertNotNull($appt->refresh()->reminder_sent_at);

        // Idempotent: a second sweep does not re-remind.
        $this->assertSame(0, $this->scheduling()->remindUpcoming());
    }

    public function test_the_remind_command_runs(): void
    {
        $this->artisan('appointments:remind')->assertSuccessful();
    }
}
