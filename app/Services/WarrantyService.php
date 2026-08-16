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
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Client claims the warranty on a completed order: spawns a follow-up visit at zero cost
     * (kind = warranty, no escrow hold), linked back via parent_order_id. The client picks the
     * revisit time, and the ORIGINAL technician is booked into that slot (a self-accepted
     * scheduled visit — they owe the fix). If the original tech is booked or gone at that time,
     * reassignment to a paid substitute is handled in a later slice. A single warranty visit per
     * order keeps it from being abused.
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

            /** @var Technician $tech */
            $tech = Technician::findOrFail($locked->technician_id);
            if ($tech->status === TechnicianStatus::Banned) {
                throw new \DomainException('The original technician is no longer available; please contact support.');
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

            // Book the original tech into the client's chosen slot (they can't refuse — they owe
            // the warranty). book() rejects a conflicting slot, surfaced as a clean 409.
            try {
                $this->schedulingService->book($warranty, $locked->technician_id);
            } catch (OfferUnavailableException) {
                throw new \DomainException('The technician is already booked at that time — please pick another.');
            }

            OrderEvent::create(['order_id' => $warranty->id, 'event_type' => OrderEventType::Created]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::WarrantyClaimed]);

            $this->notificationService->notify(
                $tech->user,
                NotificationCategory::Orders,
                'زيارة ضمان مجدولة',
                'تمت جدولة زيارة ضمان على أحد طلباتك — يُرجى الالتزام بالموعد.',
                $warranty,
            );

            return $warranty;
        });
    }
}
