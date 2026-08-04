<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WarrantyService
{
    /**
     * Client claims the warranty on a completed order: spawns a follow-up order for
     * the SAME technician at zero cost (kind = warranty, no escrow hold), linked back
     * via parent_order_id. It starts in_progress because there's no dispatch, no quote
     * and no money — the same tech simply returns to fix the covered fault. Runs under
     * the parent's lock; a single warranty visit per order keeps it from being abused.
     */
    public function claim(Order $parent, User $client, ?string $description): Order
    {
        return DB::transaction(function () use ($parent, $description): Order {
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
                'type' => $locked->type,
                'status' => OrderStatus::InProgress,
                'commission_rate' => '0.0000',
                'commission_amount' => '0',
                'inspection_fee' => '0.00',
            ]);

            OrderEvent::create(['order_id' => $warranty->id, 'event_type' => OrderEventType::Created]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::WarrantyClaimed]);

            return $warranty;
        });
    }
}
