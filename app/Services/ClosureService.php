<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Exceptions\ClosureCodeException;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClosureService
{
    /**
     * The assigned technician requests closure when the work is done. The server
     * mints a fresh code and stores it encrypted, but (per SRS note 4) never returns
     * it to the technician — the CLIENT reads it back via activeCodeFor() and shares
     * it in person, which is what proves presence + client consent.
     */
    public function generate(Order $order): void
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($order, $code) {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $locked->closure_code = $code; // encrypted at rest by the model cast
            $locked->closure_expires_at = now()->addMinutes((int) AppSetting::get('closure_code_ttl_minutes', 10));
            $locked->closure_attempts = 0;
            $locked->save();

            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::ClosureGenerated]);
        });
    }

    /** The active closure code for the client to read to the technician, or null if none/expired. */
    public function activeCodeFor(Order $order): ?string
    {
        $fresh = $order->fresh();

        if ($fresh === null
            || $fresh->closure_code === null
            || $fresh->closure_expires_at === null
            || $fresh->closure_expires_at->isPast()) {
            return null;
        }

        return $fresh->closure_code;
    }

    /**
     * Technician submits the client's code to complete the job and open the dispute
     * window. The transaction RETURNS an outcome rather than throwing, so a failed
     * attempt's counter increment actually commits (a thrown exception would roll it
     * back); the exception is raised afterwards, outside the transaction.
     */
    public function verify(Order $order, string $code): Order
    {
        $outcome = DB::transaction(function () use ($order, $code): string|Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::InProgress) {
                return 'not_in_progress';
            }

            if ($locked->closure_code === null
                || $locked->closure_expires_at === null
                || $locked->closure_expires_at->isPast()) {
                return 'no_code';
            }

            if ($locked->closure_attempts >= (int) AppSetting::get('closure_max_attempts', 5)) {
                return 'locked';
            }

            if (! hash_equals((string) $locked->closure_code, $code)) {
                $locked->closure_attempts++;
                $locked->save(); // commits, because we RETURN below instead of throwing

                return 'invalid';
            }

            $locked->status = OrderStatus::Completed;
            $locked->closure_verified_at = now();
            $locked->closure_code = null;
            $locked->closure_expires_at = null;
            $locked->dispute_deadline_at = now()->addHours((int) AppSetting::get('dispute_window_hours', 48));
            $this->applyWarranty($locked);
            $locked->save();

            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::ClosureVerified]);
            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Completed]);

            return $locked;
        });

        if ($outcome instanceof Order) {
            return $outcome;
        }

        throw match ($outcome) {
            'not_in_progress' => new \DomainException('This order is not awaiting closure.'),
            'no_code' => new ClosureCodeException('No active closure code — request closure again to mint a fresh one.'),
            'locked' => new ClosureCodeException('Too many attempts — request closure again for a new code.'),
            default => new ClosureCodeException('The closure code is incorrect.'),
        };
    }

    /**
     * Anti-deadlock: if the technician requested closure and the client neither used
     * the code nor disputed before the review window (closure_expires_at) elapsed,
     * auto-complete the order so the technician isn't held hostage. The client is
     * still fully protected by the 48h held dispute window that this opens. Because
     * no code was confirmed, we log a distinct ClosureAutoCompleted event and leave
     * closure_verified_at null — the dispute board can tell auto from code-confirmed.
     */
    public function autoCompleteStaleClosures(): int
    {
        $due = Order::query()
            ->where('status', OrderStatus::InProgress)
            ->whereNotNull('closure_expires_at')
            ->where('closure_expires_at', '<', now())
            ->whereDoesntHave('dispute', fn (Builder $query) => $query->whereNull('resolved_at'))
            ->lazyById(200);

        $completed = 0;
        foreach ($due as $order) {
            $didComplete = DB::transaction(function () use ($order): bool {
                /** @var Order $locked */
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                // Re-check under the lock: a verify or a dispute may have won the race.
                if ($locked->status !== OrderStatus::InProgress
                    || $locked->closure_expires_at === null
                    || $locked->closure_expires_at->isFuture()) {
                    return false;
                }

                $locked->status = OrderStatus::Completed;
                $locked->closure_code = null;
                $locked->closure_expires_at = null;
                $locked->dispute_deadline_at = now()->addHours((int) AppSetting::get('dispute_window_hours', 48));
                $this->applyWarranty($locked);
                $locked->save();

                OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::ClosureAutoCompleted]);
                OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Completed]);

                return true;
            });

            if ($didComplete) {
                $completed++;
            }
        }

        return $completed;
    }

    /**
     * Stamp the order's warranty on completion from the approved quote's warranty_days.
     * Sets the attribute only (the caller saves inside its own transaction); a quote
     * with 0 warranty days, or no approved quote, leaves warranty_until untouched.
     */
    private function applyWarranty(Order $locked): void
    {
        /** @var Quote|null $approved */
        $approved = $locked->quotes()->where('status', QuoteStatus::Approved)->first();
        $days = $approved !== null ? (int) $approved->warranty_days : 0;

        if ($days > 0) {
            $locked->warranty_until = now()->addDays($days);
        }
    }
}
