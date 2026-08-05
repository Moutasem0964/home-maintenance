<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\DB;

class CancellationService
{
    public function __construct(
        private readonly EscrowService $escrowService,
        private readonly SchedulingService $schedulingService,
        private readonly AssignmentService $assignmentService,
    ) {}

    /**
     * Client cancels their order. Before a technician commits (Pending) the inspection
     * fee is refunded in full; once a tech is on the hook (Accepted/Scheduled) it's a
     * late cancel — the tech keeps cancel_fee_share of the fee, the rest is refunded,
     * and the booked slot is freed. Once work is underway, cancellation is closed.
     */
    public function cancelByClient(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Pending) {
                $this->escrowService->refundOrder($locked, "cancel:{$locked->id}:refund");
            } elseif (in_array($locked->status, [OrderStatus::Accepted, OrderStatus::Scheduled], true)) {
                $this->lateCancelRefund($locked);
                $this->schedulingService->cancelFor($locked);
            } else {
                throw new \DomainException('This order can no longer be canceled.');
            }

            $locked->update(['status' => OrderStatus::Canceled]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Canceled]);

            return $locked;
        });
    }

    /** Refund the client all but the technician's cancel-fee share of the inspection fee. */
    private function lateCancelRefund(Order $order): void
    {
        $share = number_format((float) AppSetting::get('cancel_fee_share', 0.30), 2, '.', '');
        $clientShare = bcsub('1.00', $share, 2);
        $refund = bcmul((string) $order->inspection_fee, $clientShare, 2);

        // settlePartial refunds $refund to the client and releases the remainder (the
        // cancel fee) to the technician; at this stage only the inspection fee is held.
        $this->escrowService->settlePartial($order, $refund, "cancel:{$order->id}:partial");
    }

    /**
     * Technician drops a job they'd accepted but not started: free any booked slot,
     * return the order to the pool (Pending) and immediately re-dispatch to the next
     * technician. The inspection fee stays held — the order lives on.
     */
    public function technicianWithdraw(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::Accepted, OrderStatus::Scheduled], true)) {
                throw new \DomainException('This job can no longer be withdrawn.');
            }

            $this->schedulingService->cancelFor($locked);
            $locked->update(['technician_id' => null, 'status' => OrderStatus::Pending]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::TechnicianWithdrew]);

            $this->assignmentService->offerToNext($locked);

            return $locked;
        });
    }

    /**
     * Technician reports the client was absent on-site: the inspection fee is released
     * to the technician as compensation for the wasted trip, and the order closes as
     * a no-show.
     */
    public function clientNoShow(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Accepted) {
                throw new \DomainException('A no-show can only be reported once the technician is on-site.');
            }

            $locked->update(['status' => OrderStatus::NoShow]);
            $this->escrowService->releaseFunds($locked, "noshow:client:{$locked->id}");
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::NoShow]);

            return $locked;
        });
    }

    /**
     * Client reports the technician never arrived: the inspection fee is refunded in
     * full and the order closes as a no-show.
     */
    public function technicianNoShow(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Accepted) {
                throw new \DomainException('A no-show can only be reported for an accepted, un-started job.');
            }

            $this->escrowService->refundOrder($locked, "noshow:tech:{$locked->id}");
            $locked->update(['status' => OrderStatus::NoShow]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::NoShow]);

            return $locked;
        });
    }
}
