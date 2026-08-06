<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuoteService
{
    public function __construct(
        private readonly EscrowService $escrowService,
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

        return DB::transaction(function () use ($order, $data, $parts) {
            /** @var Quote $quote */
            $quote = $order->quotes()->create([
                'technician_id' => $order->technician_id,
                'type' => QuoteType::Initial,
                'labor_cost' => $data['labor_cost'],
                'warranty_days' => $data['warranty_days'] ?? 0,
                'justification' => $data['justification'] ?? null,
                'status' => QuoteStatus::Pending,
                'expires_at' => now()->addHours((int) AppSetting::get('quote_expiry_hours', 24)),
            ]);

            foreach ($parts as $part) {
                $quote->parts()->create([
                    'name' => $part['name'],
                    'price' => $part['price'],
                    'classification' => $part['classification'],
                    'image_url' => $part['image_url'],
                ]);
            }

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteSent]);

            return $quote;
        });
    }

    /** Client approves: hold the repair fee in escrow and move the order into repair. */
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
            $order->update(['status' => OrderStatus::InProgress]);

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteApproved]);
            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::WorkStarted]);

            return $order;
        });
    }

    /** Client rejects: the order closes as inspection-only and the inspection fee is released to the tech. */
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
            $order->update(['status' => OrderStatus::InspectionOnly]);
            // The technician still performed the diagnostic visit — release the inspection fee.
            $this->escrowService->releaseFunds($order, "inspection-only:{$order->id}");

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteRejected]);

            return $order;
        });
    }

    /** Sweep unanswered quotes past their expiry: mark Expired, close the order as inspection-only. */
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
                $order->update(['status' => OrderStatus::InspectionOnly]);
                $this->escrowService->releaseFunds($order, "inspection-only:{$order->id}");
                OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::QuoteExpired]);

                return true;
            });

            if ($done) {
                $expired++;
            }
        }

        return $expired;
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
