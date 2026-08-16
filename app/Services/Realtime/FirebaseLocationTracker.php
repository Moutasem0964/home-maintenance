<?php

namespace App\Services\Realtime;

use App\Contracts\LocationTracker;
use App\Models\Order;
use Kreait\Firebase\Contract\Database;

/**
 * Production driver: manages the `/orders/{id}/meta` membership node in the Realtime Database.
 * The Admin SDK bypasses security rules, so only the backend writes membership; the RTDB rules
 * then let just the order's client + assigned tech read the location, and only the assigned tech
 * write it while `active` is true.
 */
class FirebaseLocationTracker implements LocationTracker
{
    public function __construct(private readonly Database $database) {}

    public function open(Order $order): void
    {
        $techUserId = $order->technician?->user_id;
        if ($techUserId === null) {
            return; // nothing to track without an assigned technician
        }

        $this->database->getReference("orders/{$order->id}/meta")->set([
            'client_uid' => (string) $order->client_id,
            'tech_uid' => (string) $techUserId,
            'active' => true,
        ]);
    }

    public function close(Order $order): void
    {
        $this->database->getReference("orders/{$order->id}/meta/active")->set(false);
        $this->database->getReference("orders/{$order->id}/location")->remove();
    }
}
