<?php

namespace App\Services;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Models\AppSetting;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DisputeService
{
    public function __construct(
        private readonly EscrowService $escrowService,
    ) {}

    /**
     * The client raises a dispute. Two moments are valid:
     *   1. During the closure REVIEW window — the order is still InProgress, the tech
     *      has requested closure (closure_expires_at in the future), and the client
     *      objects instead of handing over the code (catches on-site damage).
     *   2. During the 48h HELD window after completion — the order is Completed and
     *      still within dispute_deadline_at.
     * Either way the order flips to Disputed, freezing the release cron until an admin
     * resolves it. Everything runs under the order lock.
     */
    public function raise(Order $order, User $client, DisputeReason $reason, ?string $description): Dispute
    {
        return DB::transaction(function () use ($order, $client, $reason, $description): Dispute {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Completed) {
                if ($locked->dispute_deadline_at === null || $locked->dispute_deadline_at->isPast()) {
                    throw new \DomainException('The dispute window has closed.');
                }
            } elseif ($locked->status === OrderStatus::InProgress) {
                if ($locked->closure_expires_at === null || $locked->closure_expires_at->isPast()) {
                    throw new \DomainException('You can only dispute once the technician requests closure, during the review window.');
                }
            } else {
                throw new \DomainException('This order cannot be disputed.');
            }

            if ($locked->hasOpenDispute()) {
                throw new \DomainException('This order already has an open dispute.');
            }

            $dispute = Dispute::create([
                'order_id' => $locked->id,
                'raised_by' => $client->id,
                'reason' => $reason,
                'description' => $description,
                'status' => DisputeStatus::Open,
            ]);

            $locked->status = OrderStatus::Disputed;
            $locked->save();

            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Disputed]);

            return $dispute;
        });
    }

    /**
     * An admin resolves the dispute and routes the escrow. The dispute + order are
     * marked resolved FIRST (so the order becomes releasable), then the money moves:
     * full refund, partial refund (FIFO split), or full release to the technician.
     */
    public function resolve(Dispute $dispute, User $admin, DisputeResolution $resolution, ?string $refundAmount): Dispute
    {
        return DB::transaction(function () use ($dispute, $admin, $resolution, $refundAmount): Dispute {
            /** @var Dispute $locked */
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DisputeStatus::Resolved) {
                throw new \DomainException('This dispute is already resolved.');
            }

            /** @var Order $order */
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();

            $locked->status = DisputeStatus::Resolved;
            $locked->resolution = $resolution;
            $locked->resolved_by = $admin->id;
            $locked->resolved_at = now();
            $locked->save();

            $order->status = OrderStatus::Resolved;
            $order->save();

            $op = "dispute:{$locked->id}";
            match ($resolution) {
                DisputeResolution::FullRefund => $this->escrowService->refundOrder($order, "{$op}:refund"),
                DisputeResolution::ReleaseToTechnician => $this->escrowService->releaseFunds($order, "{$op}:release"),
                DisputeResolution::PartialRefund => $this->escrowService->settlePartial($order, (string) $refundAmount, "{$op}:partial"),
                DisputeResolution::WarrantyOrder => $this->resolveAsWarrantyOrder($order, $op),
            };

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::DisputeResolved]);

            return $locked;
        });
    }

    /**
     * Warranty-order resolution: the work is accepted, so the held repair fee is released to the
     * technician — but the client is owed a corrective re-visit. Grant (or extend) a warranty
     * window so the client can book a free follow-up through the normal warranty-claim flow, which
     * runs the same original-tech / paid-substitute machinery as any warranty claim.
     */
    private function resolveAsWarrantyOrder(Order $order, string $op): void
    {
        $this->escrowService->releaseFunds($order, "{$op}:release");

        $window = now()->addDays((int) AppSetting::get('dispute_warranty_days', 14));
        if ($order->warranty_until === null || $order->warranty_until->lt($window)) {
            $order->warranty_until = $window;
            $order->save();
        }
    }
}
