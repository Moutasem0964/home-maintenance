<?php

namespace App\Services;

use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\ClosureCodeException;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Support\Facades\DB;

class ClosureService
{
    /**
     * Client generates a fresh closure code (returned ONLY to the client). The
     * technician obtains it from the client in person and submits it to close the
     * job — proving presence + client consent. The code is stored encrypted and is
     * never sent to the technician by the server (SRS note 4).
     */
    public function generate(Order $order): string
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

        return $code;
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
            'no_code' => new ClosureCodeException('No active closure code — ask the client to generate one.'),
            'locked' => new ClosureCodeException('Too many attempts — ask the client to generate a new code.'),
            default => new ClosureCodeException('The closure code is incorrect.'),
        };
    }
}
