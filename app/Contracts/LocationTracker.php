<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Controls the Realtime Database gate for an order's live technician location. Laravel never
 * routes GPS pings — it only opens the gate when travel begins and closes it once it should
 * stop, writing the small `/orders/{id}/meta` membership node the RTDB rules enforce against.
 */
interface LocationTracker
{
    /** Open live tracking: publish the order's membership (client + tech) and mark it active. */
    public function open(Order $order): void;

    /** Close live tracking: mark the order inactive and drop any last published location. */
    public function close(Order $order): void;
}
