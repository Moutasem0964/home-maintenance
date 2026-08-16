<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\OrderEventType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TechnicianStatus;
use App\Exceptions\OfferUnavailableException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WarrantyService
{
    public function __construct(
        private readonly SchedulingService $schedulingService,
        private readonly AssignmentService $assignmentService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Client claims the warranty on a completed order: spawns a follow-up visit at zero cost
     * to the client (kind = warranty, no escrow hold), linked back via parent_order_id. The
     * client picks the revisit time, and the ORIGINAL technician is booked into that slot (a
     * self-accepted scheduled visit — they owe the fix). If the original tech is booked or gone
     * at that time, the visit is instead dispatched to the pool for a paid substitute (the
     * platform honours the guarantee and pays the substitute the original labor cost later).
     * A single warranty visit per order keeps it from being abused.
     */
    public function claim(Order $parent, User $client, Carbon $scheduledAt, ?string $description): Order
    {
        return DB::transaction(function () use ($parent, $scheduledAt, $description): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($parent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Completed) {
                throw new \DomainException('Only a completed order has a warranty to claim.');
            }

            if ($locked->warranty_until === null || $locked->warranty_until->isPast()) {
                throw new \DomainException('The warranty for this order has expired.');
            }

            if ($locked->technician_id === null) {
                throw new \DomainException('This order has no technician to honour the warranty.');
            }

            if ($locked->childOrders()->where('kind', OrderKind::Warranty)->exists()) {
                throw new \DomainException('A warranty visit has already been requested for this order.');
            }

            /** @var Order $warranty */
            $warranty = Order::create([
                'client_id' => $locked->client_id,
                'technician_id' => $locked->technician_id,
                'service_category_id' => $locked->service_category_id,
                'address_id' => $locked->address_id,
                'parent_order_id' => $locked->id,
                'lat' => $locked->lat,
                'lng' => $locked->lng,
                'description' => $description ?? "Warranty visit for order #{$locked->id}",
                'kind' => OrderKind::Warranty,
                'type' => OrderType::Scheduled,
                'scheduled_at' => $scheduledAt,
                'status' => OrderStatus::Scheduled,
                'commission_rate' => '0.0000',
                'commission_amount' => '0',
                'inspection_fee' => '0.00',
            ]);

            OrderEvent::create(['order_id' => $warranty->id, 'event_type' => OrderEventType::Created]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::WarrantyClaimed]);

            /** @var Technician|null $tech */
            $tech = Technician::find($locked->technician_id);

            // Try to book the original tech into the client's chosen slot (they can't refuse — they
            // owe the warranty). If they're booked at that time, or no longer eligible, we don't fail
            // the claim: the visit goes to the pool for a paid substitute at the SAME slot.
            if ($tech !== null && in_array($tech->status, [TechnicianStatus::Active, TechnicianStatus::Probation], true)) {
                try {
                    $this->schedulingService->book($warranty, $locked->technician_id);

                    $this->notificationService->notify(
                        $tech->user,
                        NotificationCategory::Orders,
                        'زيارة ضمان مجدولة',
                        'تمت جدولة زيارة ضمان على أحد طلباتك — يُرجى الالتزام بالموعد.',
                        $warranty,
                    );

                    return $warranty;
                } catch (OfferUnavailableException) {
                    // fall through to pool dispatch at the same requested time
                }
            }

            $this->reassignToPool($warranty, asap: false);

            return $warranty;
        });
    }

    /**
     * Send a warranty visit to the dispatch pool for a paid substitute. Frees any slot booked
     * to the previous technician, detaches them, returns the order to Pending, and offers it to
     * the next qualified technician. When $asap is true (the booked tech no-showed and the slot
     * has passed) the visit is converted to an urgent dispatch so a substitute is found now;
     * otherwise it keeps the client's originally chosen scheduled time. The caller must already
     * hold the order lock. A Pending warranty order is never expired by the pending sweep, so it
     * waits for a substitute indefinitely — the platform's guarantee.
     */
    public function reassignToPool(Order $warranty, bool $asap): void
    {
        $this->schedulingService->cancelFor($warranty);

        $updates = ['technician_id' => null, 'status' => OrderStatus::Pending];
        if ($asap) {
            $updates['type'] = OrderType::Urgent;
            $updates['scheduled_at'] = null;
        }
        $warranty->update($updates);

        OrderEvent::create(['order_id' => $warranty->id, 'event_type' => OrderEventType::WarrantyReassigned]);

        $this->assignmentService->offerToNext($warranty);

        $this->notificationService->notify(
            $warranty->client,
            NotificationCategory::Orders,
            'جارٍ إيجاد فني بديل',
            'تعذّر حضور الفني الأصلي لزيارة الضمان — نبحث لك عن فني بديل دون أي تكلفة إضافية عليك.',
            $warranty,
        );
    }
}
