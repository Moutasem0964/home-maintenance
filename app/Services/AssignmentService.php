<?php

namespace App\Services;

use App\Contracts\LocationTracker;
use App\Enums\AppointmentStatus;
use App\Enums\DispatchOfferStatus;
use App\Enums\NotificationCategory;
use App\Enums\OrderEventType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TechnicianStatus;
use App\Exceptions\OfferUnavailableException;
use App\Models\AppSetting;
use App\Models\DispatchOffer;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public function __construct(
        private readonly SchedulingService $schedulingService,
        private readonly EscrowService $escrowService,
        private readonly NotificationService $notificationService,
        private readonly LocationTracker $locationTracker,
    ) {}

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

        // Fresh dispatch: exclude everyone already offered this order (any status).
        $exclude = $order->dispatchOffers()->pluck('technician_id')->all();

        $technician = $this->nextQualifiedTechnician($order, $exclude);
        if ($technician === null) {
            return null;
        }

        return $this->createOffer($order, $technician);
    }

    /**
     * Fallback used by the retry loop once no fresh technician is left: re-offer
     * the order to a technician who TIMED OUT on a previous offer (an expired
     * offer is "I missed it", not "no"). Never touches technicians who explicitly
     * declined, who currently hold a live offer, or who have already been offered
     * this order `max_dispatch_attempts` times. Returns the new offer, or null.
     */
    public function reofferTimedOut(Order $order): ?DispatchOffer
    {
        if ($order->status !== OrderStatus::Pending) {
            return null;
        }

        $cap = (int) AppSetting::get('max_dispatch_attempts', 3);

        // Candidates: technicians whose offer for this order timed out and who are
        // still under the attempt cap. A declined (Rejected) offer is excluded by
        // status; the unique (order, tech) index means each has exactly one row.
        $candidateIds = $order->dispatchOffers()
            ->where('status', DispatchOfferStatus::Expired)
            ->where('attempts', '<', $cap)
            ->pluck('technician_id')
            ->all();

        if ($candidateIds === []) {
            return null;
        }

        // Only re-offer one who still qualifies right now (online, in range, free).
        $technician = $this->nextQualifiedTechnician($order, [], $candidateIds);
        if ($technician === null) {
            return null;
        }

        // Reuse the existing row (the unique index forbids a second), bumping the
        // attempt counter and reopening the offer window.
        /** @var DispatchOffer $offer */
        $offer = $order->dispatchOffers()->where('technician_id', $technician->id)->firstOrFail();
        $offer->update([
            'status' => DispatchOfferStatus::Offered,
            'attempts' => $offer->attempts + 1,
            'offered_at' => now(),
            'responded_at' => null,
            'expires_at' => now()->addSeconds((int) AppSetting::get('offer_timeout_seconds', 90)),
        ]);

        OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::Dispatched]);

        $this->notifyOfferedTechnician($order, $technician);

        return $offer;
    }

    /** Persist an offer to $technician for $order and record the dispatch event. */
    private function createOffer(Order $order, Technician $technician): DispatchOffer
    {
        /** @var DispatchOffer $offer */
        $offer = DispatchOffer::create([
            'order_id' => $order->id,
            'technician_id' => $technician->id,
            'status' => DispatchOfferStatus::Offered,
            'offered_at' => now(),
            'expires_at' => now()->addSeconds((int) AppSetting::get('offer_timeout_seconds', 90)),
        ]);

        OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::Dispatched]);

        $this->notifyOfferedTechnician($order, $technician);

        return $offer;
    }

    /** Push a new-offer alert to the technician who was just offered the order. */
    private function notifyOfferedTechnician(Order $order, Technician $technician): void
    {
        $this->notificationService->notify(
            $technician->user,
            NotificationCategory::Orders,
            'طلب صيانة جديد',
            'لديك عرض طلب جديد — أمامك مهلة قصيرة للرد.',
            $order,
        );
    }

    /**
     * Pick the next technician to offer, by the rule appropriate to the order type.
     *
     * @param  array<int, int>  $exclude  technician ids that must not be offered
     * @param  array<int, int>|null  $only  if set, restrict candidates to these ids
     */
    private function nextQualifiedTechnician(Order $order, array $exclude, ?array $only = null): ?Technician
    {
        return $order->type === OrderType::Scheduled
            ? $this->nextForScheduled($order, $exclude, $only)
            : $this->nextForUrgent($order, $exclude, $only);
    }

    /**
     * Nearest active+available technician who serves the category, excluding
     * $exclude (and, when $only is given, restricted to that set).
     *
     * @param  array<int, int>  $exclude
     * @param  array<int, int>|null  $only
     */
    private function nextForUrgent(Order $order, array $exclude, ?array $only = null): ?Technician
    {
        /** @var Technician|null $technician */
        $technician = Technician::query()
            ->whereIn('status', [TechnicianStatus::Active, TechnicianStatus::Probation])
            ->where('is_available', true)
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            // Only trust a recent fix — a stale position (went online at home, then drove off)
            // must not be dispatched to. A missing/old location ping drops the tech from the pool.
            ->where('location_updated_at', '>', now()->subMinutes((int) AppSetting::get('location_ttl_minutes', 10)))
            ->whereNotIn('id', $exclude)
            ->when($only !== null, fn (Builder $query) => $query->whereIn('id', $only))
            ->whereRelation('services', 'service_categories.id', $order->service_category_id)
            ->orderByRaw(
                '(current_lat - ?) * (current_lat - ?) + (current_lng - ?) * (current_lng - ?)',
                [$order->lat, $order->lat, $order->lng, $order->lng],
            )
            ->first();

        return $technician;
    }

    /**
     * For a scheduled order, current availability/location is irrelevant — what
     * matters is whether the technician's calendar is free at the requested time.
     * Offer to a qualified tech with no appointment overlapping the booked window.
     *
     * @param  array<int, int>  $exclude
     * @param  array<int, int>|null  $only
     */
    private function nextForScheduled(Order $order, array $exclude, ?array $only = null): ?Technician
    {
        $start = $order->scheduled_at;
        if ($start === null) {
            return null;
        }
        $end = $start->copy()->addMinutes((int) AppSetting::get('appointment_duration_minutes', 120));

        /** @var Technician|null $technician */
        $technician = Technician::query()
            ->whereIn('status', [TechnicianStatus::Active, TechnicianStatus::Probation])
            ->whereNotIn('id', $exclude)
            ->when($only !== null, fn (Builder $query) => $query->whereIn('id', $only))
            ->whereRelation('services', 'service_categories.id', $order->service_category_id)
            ->whereDoesntHave('appointments', function (Builder $query) use ($start, $end) {
                $query->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Activated])
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start);
            })
            ->orderBy('id')
            ->first();

        return $technician;
    }

    /**
     * Atomically accept an offer. Under a lock on the order, verify the offer is
     * still open + unexpired and the order still pending, then assign the
     * technician and expire the losing offers. Two simultaneous accepts serialize
     * here — exactly one wins, the other gets OfferUnavailableException.
     */
    public function accept(DispatchOffer $offer): Order
    {
        $order = DB::transaction(function () use ($offer): Order {
            /** @var Order $order */
            $order = Order::whereKey($offer->order_id)->lockForUpdate()->firstOrFail();
            /** @var DispatchOffer $lockedOffer */
            $lockedOffer = DispatchOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->status !== DispatchOfferStatus::Offered
                || $lockedOffer->expires_at->isPast()
                || $order->status !== OrderStatus::Pending) {
                throw new OfferUnavailableException('This offer can no longer be accepted.');
            }

            // A scheduled order books an appointment and waits for activation; an
            // urgent one goes straight on-site. book() throws (rolling back this
            // accept) if the slot conflicts, so the tech can't be double-booked.
            if ($order->type === OrderType::Scheduled) {
                $this->schedulingService->book($order, $lockedOffer->technician_id);
                $nextStatus = OrderStatus::Scheduled;
            } else {
                $nextStatus = OrderStatus::Accepted;
            }

            // Assign from the offer itself so a wrong technician can never be attached.
            $order->update([
                'technician_id' => $lockedOffer->technician_id,
                'status' => $nextStatus,
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

        // An urgent order goes straight on-site → open live location for the trip. A scheduled
        // order only starts tracking when its appointment activates (SchedulingService).
        if ($order->status === OrderStatus::Accepted) {
            $this->locationTracker->open($order);
        }

        return $order;
    }

    /** Decline an open offer and immediately re-offer the order to the next technician. */
    public function decline(DispatchOffer $offer, ?string $reason = null): ?DispatchOffer
    {
        return DB::transaction(function () use ($offer, $reason) {
            // Same lock order as accept() (order then offer) so the re-offer can't
            // interleave with a concurrent accept on the same order.
            /** @var Order $order */
            $order = Order::whereKey($offer->order_id)->lockForUpdate()->firstOrFail();
            /** @var DispatchOffer $lockedOffer */
            $lockedOffer = DispatchOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->status !== DispatchOfferStatus::Offered || $lockedOffer->expires_at->isPast()) {
                throw new OfferUnavailableException('This offer can no longer be declined.');
            }

            $lockedOffer->update([
                'status' => DispatchOfferStatus::Rejected,
                'responded_at' => now(),
                'decline_reason' => $reason,
            ]);

            OrderEvent::create(['order_id' => $lockedOffer->order_id, 'event_type' => OrderEventType::OfferRejected]);

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
            ->lazyById(200);

        $expired = 0;

        foreach ($stale as $offer) {
            $wasExpired = DB::transaction(function () use ($offer): bool {
                // Lock the order first (same order as accept) so expire + reassign
                // serializes cleanly with a concurrent accept on the same order.
                /** @var Order $order */
                $order = Order::whereKey($offer->order_id)->lockForUpdate()->firstOrFail();
                /** @var DispatchOffer $locked */
                $locked = DispatchOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

                // A concurrent accept/decline may have resolved it since we read the batch.
                if ($locked->status !== DispatchOfferStatus::Offered || ! $locked->expires_at->isPast()) {
                    return false;
                }

                $locked->update(['status' => DispatchOfferStatus::Expired]);
                OrderEvent::create(['order_id' => $locked->order_id, 'event_type' => OrderEventType::OfferExpired]);

                $this->offerToNext($order);

                return true;
            });

            if ($wasExpired) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Safety-net re-dispatch: any order still Pending with NO live offer (nobody
     * was available when it was created, or every offer expired with no next
     * technician at the time) gets re-offered to the next qualified technician.
     * expireStaleOffers only advances orders that HAD a live offer; this covers
     * the ones it can't see. Returns how many orders were freshly offered.
     */
    public function retryPending(): int
    {
        $stuck = Order::query()
            ->where('status', OrderStatus::Pending)
            ->whereDoesntHave('dispatchOffers', function (Builder $query) {
                $query->where('status', DispatchOfferStatus::Offered)
                    ->where('expires_at', '>', now());
            })
            ->lazyById(200);

        $offered = 0;

        foreach ($stuck as $order) {
            $madeOffer = DB::transaction(function () use ($order): bool {
                /** @var Order $locked */
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== OrderStatus::Pending) {
                    return false;
                }

                // A live offer may have appeared since the batch was read.
                $hasLiveOffer = $locked->dispatchOffers()
                    ->where('status', DispatchOfferStatus::Offered)
                    ->where('expires_at', '>', now())
                    ->exists();
                if ($hasLiveOffer) {
                    return false;
                }

                // Prefer a fresh technician; if none are left, reach back to one
                // who timed out on an earlier offer (never a decliner).
                $offer = $this->offerToNext($locked) ?? $this->reofferTimedOut($locked);

                return $offer !== null;
            });

            if ($madeOffer) {
                $offered++;
            }
        }

        return $offered;
    }

    /**
     * Give up on orders that have sat Pending too long: refund the client's
     * inspection hold, close any open offers, and mark the order Expired. An
     * urgent order expires `pending_expiry_minutes` after creation; a scheduled
     * order expires once its appointment time passes with nobody having accepted
     * it. Returns how many orders were expired.
     */
    public function expireStalePending(): int
    {
        $cutoff = now()->subMinutes((int) AppSetting::get('pending_expiry_minutes', 10));

        $stale = Order::query()
            ->where('status', OrderStatus::Pending)
            // Warranty visits are the platform's obligation — they wait for a substitute
            // indefinitely and must never be refund-and-killed by this sweep.
            ->where('kind', '!=', OrderKind::Warranty)
            ->where(function (Builder $query) use ($cutoff) {
                $query->where(function (Builder $urgent) use ($cutoff) {
                    $urgent->where('type', OrderType::Urgent)
                        ->where('created_at', '<', $cutoff);
                })->orWhere(function (Builder $scheduled) {
                    $scheduled->where('type', OrderType::Scheduled)
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<', now());
                });
            })
            ->lazyById(200);

        $expired = 0;

        foreach ($stale as $order) {
            $wasExpired = DB::transaction(function () use ($order): bool {
                /** @var Order $locked */
                $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== OrderStatus::Pending) {
                    return false;
                }

                // Return the inspection fee we held at creation.
                $this->escrowService->refundOrder($locked, "expire:order:{$locked->id}");

                $locked->dispatchOffers()
                    ->where('status', DispatchOfferStatus::Offered)
                    ->update(['status' => DispatchOfferStatus::Expired]);

                $locked->update(['status' => OrderStatus::Expired]);

                OrderEvent::create(['order_id' => $locked->id, 'event_type' => OrderEventType::Expired]);

                $this->notificationService->notify(
                    $locked->client,
                    NotificationCategory::Orders,
                    'انتهت صلاحية طلبك',
                    'تعذّر إيجاد فني متاح، وتم إلغاء الطلب وإعادة رسم الكشف إلى محفظتك.',
                    $locked,
                );

                return true;
            });

            if ($wasExpired) {
                $expired++;
            }
        }

        return $expired;
    }
}
