<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\TechnicianFlagReason;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\DB;

class PartsWaitService
{
    public function __construct(
        private readonly TechnicianFlagService $flagService,
    ) {}

    /**
     * The assigned technician pauses an in-progress order to source a part. The order stays
     * assigned (the client relationship is untouched); a deadline is stamped so the overdue
     * sweep can nudge an admin if the tech never returns.
     */
    public function startWaiting(Order $order, ?string $note): Order
    {
        return DB::transaction(function () use ($order, $note): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::InProgress) {
                throw new \DomainException('Only an in-progress order can be paused for parts.');
            }

            $hours = (int) AppSetting::get('parts_wait_max_hours', 72);

            $locked->update([
                'status' => OrderStatus::WaitingForParts,
                'parts_waiting_until' => now()->addHours($hours),
                'parts_overdue_flagged_at' => null,
                'parts_note' => $note,
            ]);

            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::WaitingForParts]);

            return $locked;
        });
    }

    /** The technician returns with the part and resumes the job. */
    public function resume(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::WaitingForParts) {
                throw new \DomainException('This order is not waiting for parts.');
            }

            $locked->update([
                'status' => OrderStatus::InProgress,
                'parts_waiting_until' => null,
                'parts_overdue_flagged_at' => null,
                'parts_note' => null,
            ]);

            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::WorkStarted]);

            return $locked;
        });
    }

    /**
     * Sweep orders whose parts wait exceeded the window and raise a one-time admin flag
     * (parts_overdue_flagged_at guards against re-flagging). The order stays with the tech;
     * the admin decides whether to nudge, extend, or reassign.
     */
    public function flagOverdue(): int
    {
        $due = Order::query()
            ->where('status', OrderStatus::WaitingForParts)
            ->whereNotNull('parts_waiting_until')
            ->where('parts_waiting_until', '<', now())
            ->whereNull('parts_overdue_flagged_at')
            ->lazyById(200);

        $flagged = 0;
        foreach ($due as $order) {
            $didFlag = DB::transaction(function () use ($order): bool {
                /** @var Order $locked */
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== OrderStatus::WaitingForParts
                    || $locked->parts_waiting_until === null
                    || $locked->parts_waiting_until->isFuture()
                    || $locked->parts_overdue_flagged_at !== null) {
                    return false; // resumed / already flagged since we queued it
                }

                if ($locked->technician_id !== null) {
                    $this->flagService->raise($locked->technician_id, TechnicianFlagReason::PartsDelay, $locked->id);
                }

                $locked->update(['parts_overdue_flagged_at' => now()]);

                return true;
            });

            if ($didFlag) {
                $flagged++;
            }
        }

        return $flagged;
    }
}
