<?php

namespace App\Services\Realtime;

use App\Contracts\LocationTracker;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/** Local/testing driver: logs the gate change instead of writing to the Realtime Database. */
class LogLocationTracker implements LocationTracker
{
    public function open(Order $order): void
    {
        Log::info("[RTDB] open live location for order #{$order->id}");
    }

    public function close(Order $order): void
    {
        Log::info("[RTDB] close live location for order #{$order->id}");
    }
}
