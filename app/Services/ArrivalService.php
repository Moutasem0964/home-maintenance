<?php

namespace App\Services;

use App\Contracts\LocationTracker;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Exceptions\ArrivalOutOfRangeException;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\GeoDistance;
use Illuminate\Support\Facades\DB;

class ArrivalService
{
    public function __construct(private readonly LocationTracker $locationTracker) {}

    /**
     * The assigned technician marks arrival on-site. The server verifies their GPS is
     * within arrival_radius_meters of the order location (proof of presence), stamps
     * arrived_at, and records the event. Runs under the order lock; single-use.
     */
    public function markArrived(Order $order, float $lat, float $lng): Order
    {
        $arrived = DB::transaction(function () use ($order, $lat, $lng): Order {
            /** @var Order $locked */
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== OrderStatus::Accepted) {
                throw new \DomainException('Only an accepted order can be marked as arrived.');
            }

            if ($locked->arrived_at !== null) {
                throw new \DomainException('Arrival has already been recorded for this order.');
            }

            $radius = (float) AppSetting::get('arrival_radius_meters', 50);
            $distance = GeoDistance::meters((float) $locked->lat, (float) $locked->lng, $lat, $lng);

            if ($distance > $radius) {
                throw new ArrivalOutOfRangeException('You must be at the client location to mark arrival.');
            }

            $locked->arrived_at = now();
            $locked->save();

            OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Arrived]);

            return $locked;
        });

        // Arrival is the defined stop signal — the tech is on-site, so live tracking ends.
        $this->locationTracker->close($arrived);

        return $arrived;
    }
}
