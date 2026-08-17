<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Quote;
use App\Models\ServiceCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function __construct(
        private readonly EscrowService $escrowService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Technician sends the initial quote (labor + parts). Enforces the FR-A2
     * anomaly rule before writing anything.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInitialQuote(Order $order, array $data): Quote
    {
        /** @var array<int, array<string, mixed>> $parts */
        $parts = $data['parts'];
        $justification = isset($data['justification']) ? (string) $data['justification'] : null;

        $total = $this->totalFromInput((string) $data['labor_cost'], $parts);
        $this->assertJustifiedIfAnomalous($order, $total, $justification);

        return $this->persistQuote($order, $data, QuoteType::Initial);
    }

    /**
     * Technician sends an add-on quote for an extra fault found mid-job. There's no anomaly gate
     * — it's genuinely new work priced on its own, on top of the already-approved initial repair.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAddonQuote(Order $order, array $data): Quote
    {
        return $this->persistQuote($order, $data, QuoteType::Addon);
    }

    /**
     * Client approves: hold this quote's fee in escrow. The initial quote also starts the repair
     * (Accepted -> InProgress); an add-on just adds another held payment while work continues.
     */
    public function approve(Quote $quote): Order
    {
        return DB::transaction(function () use ($quote) {
            /** @var Order $order */
            $order = Order::whereKey($quote->order_id)->lockForUpdate()->firstOrFail();
            /** @var Quote $lockedQuote */
            $lockedQuote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($lockedQuote->status !== QuoteStatus::Pending || $lockedQuote->expires_at->isPast()) {
                throw new \DomainException('This quote can no longer be approved.');
            }

            $this->escrowService->holdFunds(
                $order,
                $this->totalFor($lockedQuote),
                PaymentType::Repair,
                "quote:{$lockedQuote->id}:repair",
                "repair:quote:{$lockedQuote->id}",
            );

            $lockedQuote->update(['status' => QuoteStatus::Approved]);
            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteApproved]);

            // Only the first (initial) quote moves the order into the repair phase.
            if ($lockedQuote->type === QuoteType::Initial) {
                $order->update(['status' => OrderStatus::InProgress]);
                OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::WorkStarted]);
            }

            return $order;
        });
    }

    /**
     * Client rejects. An initial rejection closes the order as inspection-only and releases the
     * inspection fee to the tech; an add-on rejection just declines the extra work — the order
     * keeps running on the already-approved repair.
     */
    public function reject(Quote $quote): Order
    {
        return DB::transaction(function () use ($quote) {
            /** @var Order $order */
            $order = Order::whereKey($quote->order_id)->lockForUpdate()->firstOrFail();
            /** @var Quote $lockedQuote */
            $lockedQuote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($lockedQuote->status !== QuoteStatus::Pending) {
                throw new \DomainException('This quote can no longer be rejected.');
            }

            $lockedQuote->update(['status' => QuoteStatus::Rejected]);
            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteRejected]);

            if ($lockedQuote->type === QuoteType::Initial) {
                $order->update(['status' => OrderStatus::InspectionOnly]);
                // The technician still performed the diagnostic visit — release the inspection fee.
                $this->escrowService->releaseFunds($order, "inspection-only:{$order->id}");
            }

            return $order;
        });
    }

    /**
     * Sweep unanswered quotes past their expiry. An initial quote closes the order as
     * inspection-only; an add-on simply lapses and the order carries on unchanged.
     */
    public function expireStaleQuotes(): int
    {
        $stale = Quote::query()
            ->where('status', QuoteStatus::Pending)
            ->where('expires_at', '<', now())
            ->lazyById(200);

        $expired = 0;

        foreach ($stale as $quote) {
            $done = DB::transaction(function () use ($quote): bool {
                /** @var Order $order */
                $order = Order::whereKey($quote->order_id)->lockForUpdate()->firstOrFail();
                /** @var Quote $locked */
                $locked = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== QuoteStatus::Pending || ! $locked->expires_at->isPast()) {
                    return false;
                }

                $locked->update(['status' => QuoteStatus::Expired]);
                OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteExpired]);

                if ($locked->type === QuoteType::Initial) {
                    $order->update(['status' => OrderStatus::InspectionOnly]);
                    $this->escrowService->releaseFunds($order, "inspection-only:{$order->id}");
                }

                return true;
            });

            if ($done) {
                $expired++;
                /** @var Order $order */
                $order = $quote->order()->firstOrFail();
                $this->notifyExpiry($order, $quote->type);
            }
        }

        return $expired;
    }

    private function notifyExpiry(Order $order, QuoteType $type): void
    {
        if ($type === QuoteType::Addon) {
            $this->notificationService->notify(
                $order->client,
                NotificationCategory::Orders,
                'انتهت صلاحية العرض الإضافي',
                'انتهت مهلة العرض الإضافي دون رد — يستمر الطلب كالمعتاد.',
                $order,
            );

            return;
        }

        $this->notificationService->notify(
            $order->client,
            NotificationCategory::Orders,
            'انتهت صلاحية العرض',
            'انتهت مهلة عرض السعر — أصبح الطلب كشفاً فقط.',
            $order,
        );
        if ($order->technician_id !== null) {
            $this->notificationService->notify(
                $order->technician->user,
                NotificationCategory::Orders,
                'انتهت صلاحية عرضك',
                'لم يوافق العميل على عرض السعر ضمن المهلة.',
                $order,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistQuote(Order $order, array $data, QuoteType $type): Quote
    {
        /** @var array<int, array<string, mixed>> $parts */
        $parts = $data['parts'];

        return DB::transaction(function () use ($order, $data, $parts, $type) {
            /** @var Quote $quote */
            $quote = $order->quotes()->create([
                'technician_id' => $order->technician_id,
                'type' => $type,
                'labor_cost' => $data['labor_cost'],
                'warranty_days' => $data['warranty_days'] ?? 0,
                'justification' => $data['justification'] ?? null,
                'status' => QuoteStatus::Pending,
                'expires_at' => now()->addHours((int) AppSetting::get('quote_expiry_hours', 24)),
            ]);

            foreach ($parts as $part) {
                /** @var UploadedFile $image */
                $image = $part['image'];
                $quote->parts()->create([
                    'name' => $part['name'],
                    'price' => $part['price'],
                    'classification' => $part['classification'],
                    // Stored privately; the resource turns this path into a streaming URL.
                    'image_url' => $image->store("quotes/{$quote->id}/parts", 'local'),
                ]);
            }

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteSent]);

            return $quote;
        });
    }

    /** @param  array<int, array<string, mixed>>  $parts */
    private function totalFromInput(string $laborCost, array $parts): string
    {
        $total = $laborCost;
        foreach ($parts as $part) {
            $total = bcadd($total, (string) $part['price'], 2);
        }

        return $total;
    }

    private function totalFor(Quote $quote): string
    {
        return bcadd((string) $quote->labor_cost, (string) $quote->parts()->sum('price'), 2);
    }

    private function assertJustifiedIfAnomalous(Order $order, string $total, ?string $justification): void
    {
        /** @var ServiceCategory|null $category */
        $category = $order->serviceCategory;
        $guidePrice = $category?->guide_price;

        if ($guidePrice === null) {
            return; // no benchmark for this category → no anomaly rule to apply
        }

        $threshold = bcmul((string) $guidePrice, (string) AppSetting::get('price_anomaly_multiplier', 2.0), 2);

        if (bccomp($total, $threshold, 2) > 0 && ($justification === null || trim($justification) === '')) {
            throw ValidationException::withMessages([
                'justification' => 'A justification is required because the quote exceeds the expected price range.',
            ]);
        }
    }
}
