<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\OfferUnavailableException;
use App\Models\Appointment;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SchedulingService
{
    /**
     * Book the technician's visit for a scheduled order at its requested time. Called
     * inside AssignmentService::accept (already under the order lock). Rejects an
     * overlapping slot up front, and the UNIQUE(technician_id, starts_at) index is the
     * final race-proof guard — either surfaces as OfferUnavailableException so the tech
     * gets a clean "already booked" rather than a silent double-booking.
     */
    public function book(Order $order, int $technicianId): Appointment
    {
        if ($order->scheduled_at === null) {
            throw new \DomainException('Only a scheduled order can be booked.');
        }

        $start = $order->scheduled_at;
        $end = $start->copy()->addMinutes((int) AppSetting::get('appointment_duration_minutes', 120));

        if ($this->hasConflict($technicianId, $start, $end)) {
            throw new OfferUnavailableException('The technician is already booked for that time.');
        }

        try {
            $appointment = Appointment::create([
                'order_id' => $order->id,
                'technician_id' => $technicianId,
                'type' => AppointmentType::Inspection,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => AppointmentStatus::Confirmed,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new OfferUnavailableException('The technician is already booked for that time.');
        }

        OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::AppointmentBooked]);

        return $appointment;
    }

    /** Cancel every live appointment on an order (freeing the slot) — used on cancel / withdraw. */
    public function cancelFor(Order $order): void
    {
        Appointment::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Activated])
            ->update(['status' => AppointmentStatus::Canceled]);
    }

    /** True if the technician has a live (confirmed/activated) appointment overlapping [$start, $end). */
    public function hasConflict(int $technicianId, Carbon $start, Carbon $end): bool
    {
        return Appointment::query()
            ->where('technician_id', $technicianId)
            ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Activated])
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
    }

    /**
     * When the appointment time arrives, flip the booking to activated and drop the
     * order into the normal on-site lifecycle (Scheduled -> Accepted, ready for the
     * inspection + quote). Each row is handled under a lock, re-checked after locking.
     */
    public function activateDue(): int
    {
        $due = Appointment::query()
            ->where('status', AppointmentStatus::Confirmed)
            ->where('starts_at', '<=', now())
            ->lazyById(200);

        $activated = 0;
        foreach ($due as $appointment) {
            $didActivate = DB::transaction(function () use ($appointment): bool {
                /** @var Appointment $locked */
                $locked = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== AppointmentStatus::Confirmed || $locked->starts_at->isFuture()) {
                    return false;
                }

                /** @var Order $order */
                $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
                if ($order->status !== OrderStatus::Scheduled) {
                    return false; // order was canceled / moved on — leave the booking be
                }

                $locked->update(['status' => AppointmentStatus::Activated]);
                $order->update(['status' => OrderStatus::Accepted]);

                OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::AppointmentActivated]);

                return true;
            });

            if ($didActivate) {
                $activated++;
            }
        }

        return $activated;
    }

    /**
     * Send a one-time reminder for confirmed appointments starting within the lead
     * window (UC-26). reminder_sent_at both records it and makes the sweep idempotent,
     * so a re-run of the scheduler never double-reminds.
     */
    public function remindUpcoming(): int
    {
        $lead = (int) AppSetting::get('appointment_reminder_minutes', 60);

        $upcoming = Appointment::query()
            ->where('status', AppointmentStatus::Confirmed)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes($lead))
            ->lazyById(200);

        $reminded = 0;
        foreach ($upcoming as $appointment) {
            // TODO(notifications): dispatch the actual reminder (push/SMS) here.
            $appointment->update(['reminder_sent_at' => now()]);
            $reminded++;
        }

        return $reminded;
    }
}
