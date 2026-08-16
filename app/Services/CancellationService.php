<?php

namespace App\Services;

use App\Contracts\LocationTracker;
use App\Enums\OrderEventType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianFlagReason;
use App\Enums\TechnicianFlagStatus;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\TechnicianFlag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancellationService
{
    public function __construct(
        private readonly EscrowService $escrowService,
        private readonly SchedulingService $schedulingService,
        private readonly AssignmentService $assignmentService,
        private readonly TechnicianFlagService $flagService,
        private readonly WarrantyService $warrantyService,
        private readonly LocationTracker $locationTracker,
    ) {}

    /**
     * Client cancels their order. The inspection-fee outcome depends on how far along it is:
     *   - Pending (no tech committed): refunded in full.
     *   - Accepted/Scheduled but the tech has NOT arrived: split (cancel_fee_share = 50/50),
     *     the rest refunded, the booked slot freed.
     *   - Accepted and the tech has ALREADY arrived (arrived_at set): recorded as a no-show —
     *     the whole inspection fee is released to the technician (they made the trip).
     *   - In progress or later: cancellation is closed (dispute route only).
     */
    public function cancelByClient(Order $order): Order
    {
        // Cancelling from a committed state ends any live-location tracking that was open.
        $wasTracked = in_array($order->status, [OrderStatus::Accepted, OrderStatus::Scheduled], true);

        $result = DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Pending) {
                $this->escrowService->refundOrder($locked, "cancel:{$locked->id}:refund");
                $locked->update(['status' => OrderStatus::Canceled]);
                OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Canceled]);
            } elseif (in_array($locked->status, [OrderStatus::Accepted, OrderStatus::Scheduled], true)) {
                $this->schedulingService->cancelFor($locked);

                if ($locked->arrived_at !== null) {
                    // Tech already reached the site → effectively a client no-show. Set the
                    // status FIRST (isReleasable allows NoShow), then release the full fee.
                    $locked->update(['status' => OrderStatus::NoShow]);
                    $this->escrowService->releaseFunds($locked, "cancel:{$locked->id}:release");
                    OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::NoShow]);
                } else {
                    // Committed but not yet on-site → split the inspection fee 50/50.
                    $this->lateCancelRefund($locked);
                    $locked->update(['status' => OrderStatus::Canceled]);
                    OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Canceled]);
                }
            } else {
                throw new \DomainException('This order can no longer be canceled.');
            }

            return $locked;
        });

        if ($wasTracked) {
            $this->locationTracker->close($result);
        }

        return $result;
    }

    /** Refund the client all but the technician's cancel-fee share of the inspection fee. */
    private function lateCancelRefund(Order $order): void
    {
        $share = number_format((float) AppSetting::get('cancel_fee_share', 0.50), 2, '.', '');
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
        $result = DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::Accepted, OrderStatus::Scheduled], true)) {
                throw new \DomainException('This job can no longer be withdrawn.');
            }

            $technicianId = $locked->technician_id; // capture before the detach nulls it

            $this->schedulingService->cancelFor($locked);
            $locked->update(['technician_id' => null, 'status' => OrderStatus::Pending]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::TechnicianWithdrew]);

            // Flag the offense for admin assessment (no auto-sanction).
            if ($technicianId !== null) {
                $this->flagService->raise($technicianId, TechnicianFlagReason::Withdrawal, $locked->id);
            }

            $this->assignmentService->offerToNext($locked);

            return $locked;
        });

        // The withdrawing tech is gone — close their live-location gate (a new tech reopens it on accept).
        $this->locationTracker->close($result);

        return $result;
    }

    /**
     * Technician reports the client was absent on-site. Mirrors the technician no-show flow:
     * the system does NOT pay out — the inspection fee stays held and a claim is raised for
     * admin review (one open claim per order). An admin then confirms (releasing the fee to
     * the technician) or dismisses (the order carries on) via resolveNoShow(). The tech's
     * geofenced arrived_at is available to the admin as evidence.
     */
    public function clientNoShow(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Accepted) {
                throw new \DomainException('A no-show can only be reported once the technician is on-site.');
            }

            if ($locked->technician_id === null) {
                throw new \DomainException('This order has no technician to file a no-show claim.');
            }

            if ($this->openClientNoShowFlag($locked) !== null) {
                throw new \DomainException('A client no-show report is already under review for this order.');
            }

            $this->flagService->raise($locked->technician_id, TechnicianFlagReason::ClientNoShow, $locked->id);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::ClientNoShowReported]);

            return $locked;
        });
    }

    /**
     * Client reports the technician never arrived: the inspection fee is refunded in
     * full and the order closes as a no-show.
     */
    /**
     * The client reports a suspected technician no-show. Redesigned: the system does NOT
     * act — no refund, no status change, the inspection fee stays held. It only raises a
     * flag for admin observation (one open report per order). An admin then confirms or
     * dismisses via resolveTechnicianNoShow(); the tech's geofenced arrived_at is available
     * to the admin as evidence.
     */
    public function technicianNoShow(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // A normal order is reported before it starts (Accepted). A warranty visit has no
            // quote step, so activation drops it straight to InProgress — that is the window in
            // which a no-show for the booked warranty tech is reported.
            $warrantyInProgress = $locked->kind === OrderKind::Warranty && $locked->status === OrderStatus::InProgress;
            if ($locked->status !== OrderStatus::Accepted && ! $warrantyInProgress) {
                throw new \DomainException('A no-show can only be reported for an accepted, un-started job.');
            }

            if ($locked->technician_id === null) {
                throw new \DomainException('This order has no technician to report.');
            }

            if ($this->openNoShowFlag($locked) !== null) {
                throw new \DomainException('A no-show report is already under review for this order.');
            }

            $this->flagService->raise($locked->technician_id, TechnicianFlagReason::NoShow, $locked->id);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::NoShowReported]);

            return $locked;
        });
    }

    /**
     * An admin resolves a reported no-show — from either party — routing by the open claim on
     * the order. Dismissed → nothing moves, the order carries on. Confirmed → the money moves per
     * the claim: a technician no-show refunds the client in full and frees any booked slot; a
     * client no-show releases the full inspection fee to the technician (they made the trip).
     * Either way the order closes as no_show and the flag is upheld as a record.
     */
    public function resolveNoShow(Order $order, User $admin, bool $confirmed, ?string $note): Order
    {
        return DB::transaction(function () use ($order, $admin, $confirmed, $note): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $flag = $this->openNoShowFlag($locked) ?? $this->openClientNoShowFlag($locked);
            if ($flag === null) {
                throw new \DomainException('There is no open no-show report to resolve for this order.');
            }

            if ($confirmed) {
                $warrantyNoShow = $locked->kind === OrderKind::Warranty
                    && $flag->reason === TechnicianFlagReason::NoShow;

                // A warranty visit is reported in-progress; every other no-show is reported
                // before the job starts (Accepted).
                $closable = $warrantyNoShow
                    ? $locked->status === OrderStatus::InProgress
                    : $locked->status === OrderStatus::Accepted;
                if (! $closable) {
                    throw new \DomainException('This order can no longer be closed as a no-show.');
                }

                if ($warrantyNoShow) {
                    // The booked (original) warranty tech didn't show. There's no client money to
                    // refund — the platform owes the fix. Reassign the visit to the pool ASAP for a
                    // paid substitute; the order lives on rather than closing as a no-show.
                    $this->warrantyService->reassignToPool($locked, asap: true);
                } elseif ($flag->reason === TechnicianFlagReason::NoShow) {
                    // Technician confirmed absent → refund the client in full, free any slot.
                    $this->escrowService->refundOrder($locked, "noshow:confirm:{$locked->id}");
                    $this->schedulingService->cancelFor($locked);
                    $locked->update(['status' => OrderStatus::NoShow]);
                    OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::NoShow]);
                } else {
                    // Client confirmed absent → release the full inspection fee to the technician.
                    $locked->update(['status' => OrderStatus::NoShow]);
                    $this->escrowService->releaseFunds($locked, "noshow:client:confirm:{$locked->id}");
                    OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::NoShow]);
                }
            }

            $this->flagService->resolve(
                $flag,
                $admin,
                $confirmed ? TechnicianFlagOutcome::Upheld : TechnicianFlagOutcome::Dismissed,
                $note,
            );

            return $locked;
        });
    }

    private function openNoShowFlag(Order $order): ?TechnicianFlag
    {
        return TechnicianFlag::query()
            ->where('order_id', $order->id)
            ->where('reason', TechnicianFlagReason::NoShow)
            ->where('status', TechnicianFlagStatus::Open)
            ->first();
    }

    private function openClientNoShowFlag(Order $order): ?TechnicianFlag
    {
        return TechnicianFlag::query()
            ->where('order_id', $order->id)
            ->where('reason', TechnicianFlagReason::ClientNoShow)
            ->where('status', TechnicianFlagStatus::Open)
            ->first();
    }
}
