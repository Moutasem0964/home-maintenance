<?php

namespace App\Services;

use App\Enums\DispatchOfferStatus;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\TechnicianStatus;
use App\Exceptions\OfferUnavailableException;
use App\Models\AppSetting;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Technician;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /**
     * Offer a pending order to the next best not-yet-offered technician. Returns
     * the created offer, or null when nobody qualifies (the order stays pending
     * for a later retry / timeout-reassign cron).
     */
    public function offerToNext(Order $order): ?DispatchOffer
    {
        if ($order->status !== OrderStatus::Pending) {
            return null;
        }

        $technician = $this->nextQualifiedTechnician($order);
        if ($technician === null) {
            return null;
        }

        /** @var DispatchOffer $offer */
        $offer = DispatchOffer::create([
            'order_id' => $order->id,
            'technician_id' => $technician->id,
            'status' => DispatchOfferStatus::Offered,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds((int) AppSetting::get('offer_timeout_seconds', 90)),
        ]);

        OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::Dispatched]);

        return $offer;
    }

    /** Nearest active+available technician who serves the category and hasn't been offered this order yet. */
    private function nextQualifiedTechnician(Order $order): ?Technician
    {
        /** @var Technician|null $technician */
        $technician = Technician::query()
            ->whereIn('status', [TechnicianStatus::Active, TechnicianStatus::Probation])
            ->where('is_available', true)
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->whereNotIn('id', $order->dispatchOffers()->pluck('technician_id'))
            ->whereRelation('services', 'service_categories.id', $order->service_category_id)
            ->orderByRaw(
                '(current_lat - ?) * (current_lat - ?) + (current_lng - ?) * (current_lng - ?)',
                [$order->lat, $order->lat, $order->lng, $order->lng],
            )
            ->first();

        return $technician;
    }

    /**
     * Atomically accept an offer. Under a lock on the order, verify the offer is
     * still open + unexpired and the order still pending, then assign the
     * technician and expire the losing offers. Two simultaneous accepts serialize
     * here — exactly one wins, the other gets OfferUnavailableException.
     */
    public function accept(DispatchOffer $offer, Technician $technician): Order
    {
        return DB::transaction(function () use ($offer, $technician) {
            /** @var Order $order */
            $order = Order::whereKey($offer->order_id)->lockForUpdate()->firstOrFail();
            /** @var DispatchOffer $lockedOffer */
            $lockedOffer = DispatchOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->status !== DispatchOfferStatus::Offered
                || $lockedOffer->expires_at->isPast()
                || $order->status !== OrderStatus::Pending) {
                throw new OfferUnavailableException('This offer can no longer be accepted.');
            }

            $order->update([
                'technician_id' => $technician->id,
                'status' => OrderStatus::Accepted,
            ]);

            $lockedOffer->update([
                'status' => DispatchOfferStatus::Accepted,
                'responded_at' => now(),
            ]);

            // Every other open offer for this order lost the race.
            $order->dispatchOffers()
                ->where('id', '!=', $lockedOffer->id)
                ->where('status', DispatchOfferStatus::Offered)
                ->update(['status' => DispatchOfferStatus::Expired]);

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::OfferAccepted]);

            return $order;
        });
    }

    /** Decline an open offer and immediately re-offer the order to the next technician. */
    public function decline(DispatchOffer $offer, ?string $reason = null): ?DispatchOffer
    {
        return DB::transaction(function () use ($offer, $reason) {
            /** @var DispatchOffer $lockedOffer */
            $lockedOffer = DispatchOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->status !== DispatchOfferStatus::Offered) {
                throw new OfferUnavailableException('This offer can no longer be declined.');
            }

            $lockedOffer->update([
                'status' => DispatchOfferStatus::Rejected,
                'responded_at' => now(),
                'decline_reason' => $reason,
            ]);

            OrderEvent::create(['order_id' => $lockedOffer->order_id, 'event_type' => OrderEventType::OfferRejected]);

            /** @var Order $order */
            $order = $lockedOffer->order()->firstOrFail();

            return $this->offerToNext($order);
        });
    }

    /**
     * Sweep timed-out offers: mark each Expired and re-offer its order to the next
     * technician (strict — the ignoring tech is not re-offered, they're already in
     * dispatch_offers). Returns how many offers were expired. Runs on a schedule.
     */
    public function expireStaleOffers(): int
    {
        $stale = DispatchOffer::query()
            ->where('status', DispatchOfferStatus::Offered)
            ->where('expires_at', '<', now())
            ->get();

        $expired = 0;

        foreach ($stale as $offer) {
            $wasExpired = DB::transaction(function () use ($offer): bool {
                /** @var DispatchOffer $locked */
                $locked = DispatchOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

                // A concurrent accept/decline may have resolved it since we read the batch.
                if ($locked->status !== DispatchOfferStatus::Offered || ! $locked->expires_at->isPast()) {
                    return false;
                }

                $locked->update(['status' => DispatchOfferStatus::Expired]);
                OrderEvent::create(['order_id' => $locked->order_id, 'event_type' => OrderEventType::OfferExpired]);

                /** @var Order $order */
                $order = $locked->order()->firstOrFail();
                $this->offerToNext($order);

                return true;
            });

            if ($wasExpired) {
                $expired++;
            }
        }

        return $expired;
    }
}
