<?php

namespace App\Services;

use App\Enums\BalanceType;
use App\Enums\OrderEventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\TxnType;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escrow money engine: hold / release / refund against an append-only wallet ledger.
 *
 * CONTRACT — every method takes a caller-supplied $operationId that must be a
 * STABLE, BOUNDED token (a UUID or ULID), derived from the domain event, never
 * free text. It is embedded in the ledger `reference` (a unique index): a retry
 * of the same operation reuses the id and collides (idempotent), while distinct
 * operations use distinct ids. The `reference` column is 255 chars — keep the id
 * bounded or the ledger insert will fail.
 */
class EscrowService
{
    public function __construct(
        private readonly PlatformService $platformService,
    ) {}

    private function isReleasable(Order $order): bool
    {
        return match ($order->status) {
            // Completed = normal settlement after the dispute window.
            // Resolved  = an admin resolved a dispute in the technician's favour.
            // NoShow    = client didn't show; the inspection fee goes to the technician.
            OrderStatus::Completed, OrderStatus::Resolved, OrderStatus::NoShow => ! $order->hasOpenDispute(),
            default => false,
        };
    }

    private function recordLedgerEntry(
        Wallet $wallet,
        Payment $payment,
        Order $order,
        TxnType $type,
        BalanceType $balanceType,
        string $amount,
        string $description,
        string $operationId
    ): void {
        $wallet->transactions()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'type' => $type,
            'balance_type' => $balanceType,
            'amount' => $amount,
            'reference' => "escrow:{$type->value}:P_ID{$payment->id}:W_ID{$wallet->id}:B_TYPE{$balanceType->value}:OP_ID{$operationId}",
            'description' => $description,
        ]);
    }

    public function holdFunds(Order $order, string $amount, PaymentType $paymentType, string $idempotencyKey, string $operationId): Payment
    {
        $existingPayment = $order->payments()->where('idempotency_key', $idempotencyKey)->first();
        if ($existingPayment) {
            return $existingPayment;
        }

        return DB::transaction(function () use ($order, $amount, $paymentType, $idempotencyKey, $operationId) {
            $client = $order->client;
            $wallet = $client->wallet()->lockForUpdate()->firstOrFail();

            $payment = Payment::create([
                'order_id' => $order->id,
                'payer_id' => $client->id,
                'payee_id' => null,
                'type' => $paymentType,
                'amount' => $amount,
                'commission_amount' => bcmul($amount, $order->commission_rate, 2),
                'status' => PaymentStatus::Held,
                'idempotency_key' => $idempotencyKey,
                'held_at' => now(),
            ]);

            $this->recordLedgerEntry(
                $wallet,
                $payment,
                $order,
                TxnType::Hold,
                BalanceType::Available,
                bcmul($amount, '-1', 2),
                'Move funds from available to held for order #'.$order->id,
                $operationId
            );
            $this->recordLedgerEntry(
                $wallet,
                $payment,
                $order,
                TxnType::Hold,
                BalanceType::Held,
                $amount,
                'Funds held in escrow for order #'.$order->id,
                $operationId
            );

            $wallet->decreaseAvailableBalance($amount);
            $wallet->increaseHeldBalance($amount);

            OrderEvent::create([
                'order_id' => $order->id,
                'event_type' => OrderEventType::FundsHeld,
            ]);

            return $payment;
        });
    }

    public function releaseFunds(Order $order, string $operationId): void
    {
        DB::transaction(function () use ($order, $operationId) {

            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $this->isReleasable($order)) {
                return;
            }

            $platform = $this->platformService->account();

            $platformWallet = $platform->wallet()->lockForUpdate()->firstOrFail();
            $payeeWallet = $order->technician->user->wallet()->lockForUpdate()->firstOrFail();
            $payerWallet = $order->client->wallet()->lockForUpdate()->firstOrFail();

            $payments = $order->payments()->where('status', PaymentStatus::Held)->get();
            foreach ($payments as $payment) {
                $this->recordLedgerEntry(
                    $payerWallet,
                    $payment,
                    $order,
                    TxnType::Release,
                    BalanceType::Held,
                    bcmul($payment->amount, '-1', 2),
                    'Release funds from held for order #'.$order->id,
                    $operationId
                );

                $commissionAmount = $payment->commission_amount;
                $technicianCut = bcsub($payment->amount, $commissionAmount, 2);

                $this->recordLedgerEntry(
                    $payeeWallet,
                    $payment,
                    $order,
                    TxnType::Release,
                    BalanceType::Available,
                    $technicianCut,
                    'Funds released to payee for order #'.$order->id,
                    $operationId
                );

                $this->recordLedgerEntry(
                    $platformWallet,
                    $payment,
                    $order,
                    TxnType::Release,
                    BalanceType::Available,
                    $commissionAmount,
                    'Commission received for order #'.$order->id,
                    $operationId
                );

                $payerWallet->decreaseHeldBalance($payment->amount);
                $payeeWallet->increaseAvailableBalance($technicianCut);
                $platformWallet->increaseAvailableBalance($commissionAmount);

                $payment->update([
                    'status' => PaymentStatus::Released,
                    'payee_id' => $order->technician->user->id,
                    'released_at' => now(),
                ]);

                OrderEvent::create([
                    'order_id' => $order->id,
                    'event_type' => OrderEventType::FundsReleased,
                ]);
            }
        });
    }

    public function refund(Order $order, string $amount, string $operationId): void
    {
        DB::transaction(function () use ($order, $amount, $operationId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $heldPayments = $order->payments()->where('status', PaymentStatus::Held)->get();

            if ($heldPayments->count() !== 1) {
                // Multi-payment allocation (which payment, proportional vs FIFO) is a
                // dispute-flow business decision — deferred
                throw new \DomainException(
                    "refund() supports exactly one held payment, found {$heldPayments->count()} on order #{$order->id}"
                );
            }

            $payment = $heldPayments->first();

            // Guard: never refund more than what is still held on this payment.
            if (bccomp($amount, $payment->amount, 2) > 0) {
                throw new \DomainException(
                    "Refund amount {$amount} exceeds held amount {$payment->amount} on payment #{$payment->id}"
                );
            }

            $clientWallet = $order->client->wallet()->lockForUpdate()->firstOrFail();

            $this->recordLedgerEntry(
                $clientWallet,
                $payment,
                $order,
                TxnType::Refund,
                BalanceType::Held,
                bcmul($amount, '-1', 2),
                'Refund released from held for order #'.$order->id,
                $operationId
            );
            $this->recordLedgerEntry(
                $clientWallet,
                $payment,
                $order,
                TxnType::Refund,
                BalanceType::Available,
                $amount,
                'Refund returned to available for order #'.$order->id,
                $operationId
            );

            $clientWallet->decreaseHeldBalance($amount);
            $clientWallet->increaseAvailableBalance($amount);

            $isFull = bccomp($amount, $payment->amount, 2) === 0;

            $payment->update([
                'status' => $isFull ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                'refunded_at' => now(),
            ]);

            OrderEvent::create([
                'order_id' => $order->id,
                'event_type' => OrderEventType::Refunded,
            ]);
        });
    }

    /**
     * Release funds for every completed order whose dispute window has closed with
     * no open dispute. releaseFunds re-checks releasability under a lock, so this is
     * safe to run repeatedly; the held-payment filter keeps already-settled orders out.
     */
    public function releaseSettledOrders(): int
    {
        $due = Order::query()
            ->where('status', OrderStatus::Completed)
            ->whereNotNull('dispute_deadline_at')
            ->where('dispute_deadline_at', '<', now())
            ->whereDoesntHave('dispute', fn (Builder $query) => $query->whereNull('resolved_at'))
            ->whereHas('payments', fn (Builder $query) => $query->where('status', PaymentStatus::Held))
            ->lazyById(200);

        $released = 0;
        foreach ($due as $order) {
            $this->releaseFunds($order, "release:order:{$order->id}");
            $released++;
        }

        return $released;
    }

    /**
     * Full refund: return every held payment on the order to the client (held ->
     * available). Used by a dispute resolved fully in the client's favour.
     */
    public function refundOrder(Order $order, string $operationId): void
    {
        DB::transaction(function () use ($order, $operationId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $clientWallet = $order->client->wallet()->lockForUpdate()->firstOrFail();

            $payments = $order->payments()->where('status', PaymentStatus::Held)->get();
            foreach ($payments as $payment) {
                $this->recordLedgerEntry(
                    $clientWallet, $payment, $order, TxnType::Refund, BalanceType::Held,
                    bcmul($payment->amount, '-1', 2),
                    'Refund released from held for order #'.$order->id, $operationId
                );
                $this->recordLedgerEntry(
                    $clientWallet, $payment, $order, TxnType::Refund, BalanceType::Available,
                    $payment->amount,
                    'Refund returned to available for order #'.$order->id, $operationId
                );

                $clientWallet->decreaseHeldBalance($payment->amount);
                $clientWallet->increaseAvailableBalance($payment->amount);

                $payment->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);
            }

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::Refunded]);
        });
    }

    /**
     * Partial refund: refund $refundAmount to the client and release the remainder to
     * the technician (commission charged on the released portion only). The refund is
     * allocated FIFO across the held payments — each payment is either fully refunded,
     * fully released, or split once — which avoids proportional-rounding ambiguity.
     */
    public function settlePartial(Order $order, string $refundAmount, string $operationId): void
    {
        DB::transaction(function () use ($order, $refundAmount, $operationId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $platformWallet = $this->platformService->account()->wallet()->lockForUpdate()->firstOrFail();
            $payeeWallet = $order->technician->user->wallet()->lockForUpdate()->firstOrFail();
            $clientWallet = $order->client->wallet()->lockForUpdate()->firstOrFail();

            /** @var Collection<int, Payment> $payments */
            $payments = $order->payments()->where('status', PaymentStatus::Held)->orderBy('id')->get();
            $totalHeld = $payments->reduce(fn (string $carry, Payment $p): string => bcadd($carry, $p->amount, 2), '0.00');

            if (bccomp($refundAmount, '0', 2) <= 0 || bccomp($refundAmount, $totalHeld, 2) >= 0) {
                throw new \DomainException("Partial refund {$refundAmount} must be between 0 and the held total {$totalHeld}.");
            }

            $remainingRefund = $refundAmount; // still to refund, consumed FIFO
            foreach ($payments as $payment) {
                $refundShare = bccomp($remainingRefund, $payment->amount, 2) >= 0 ? $payment->amount : $remainingRefund;
                $releaseShare = bcsub($payment->amount, $refundShare, 2);
                $remainingRefund = bcsub($remainingRefund, $refundShare, 2);

                if (bccomp($refundShare, '0', 2) > 0) {
                    $this->recordLedgerEntry(
                        $clientWallet, $payment, $order, TxnType::Refund, BalanceType::Held,
                        bcmul($refundShare, '-1', 2), 'Partial refund from held for order #'.$order->id, $operationId
                    );
                    $this->recordLedgerEntry(
                        $clientWallet, $payment, $order, TxnType::Refund, BalanceType::Available,
                        $refundShare, 'Partial refund to available for order #'.$order->id, $operationId
                    );
                    $clientWallet->decreaseHeldBalance($refundShare);
                    $clientWallet->increaseAvailableBalance($refundShare);
                }

                if (bccomp($releaseShare, '0', 2) > 0) {
                    $commission = bcmul($releaseShare, $order->commission_rate, 2);
                    $technicianCut = bcsub($releaseShare, $commission, 2);

                    $this->recordLedgerEntry(
                        $clientWallet, $payment, $order, TxnType::Release, BalanceType::Held,
                        bcmul($releaseShare, '-1', 2), 'Release remainder from held for order #'.$order->id, $operationId
                    );
                    $this->recordLedgerEntry(
                        $payeeWallet, $payment, $order, TxnType::Release, BalanceType::Available,
                        $technicianCut, 'Funds released to payee for order #'.$order->id, $operationId
                    );
                    $this->recordLedgerEntry(
                        $platformWallet, $payment, $order, TxnType::Release, BalanceType::Available,
                        $commission, 'Commission received for order #'.$order->id, $operationId
                    );
                    $clientWallet->decreaseHeldBalance($releaseShare);
                    $payeeWallet->increaseAvailableBalance($technicianCut);
                    $platformWallet->increaseAvailableBalance($commission);
                }

                $status = match (true) {
                    bccomp($releaseShare, '0', 2) === 0 => PaymentStatus::Refunded,
                    bccomp($refundShare, '0', 2) === 0 => PaymentStatus::Released,
                    default => PaymentStatus::PartiallyRefunded,
                };

                $payment->update([
                    'status' => $status,
                    'payee_id' => bccomp($releaseShare, '0', 2) > 0 ? $order->technician->user->id : $payment->payee_id,
                    'refunded_at' => bccomp($refundShare, '0', 2) > 0 ? now() : $payment->refunded_at,
                    'released_at' => bccomp($releaseShare, '0', 2) > 0 ? now() : $payment->released_at,
                ]);
            }

            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::FundsReleased]);
            OrderEvent::create(['order_id' => $order->id, 'event_type' => OrderEventType::Refunded]);
        });
    }
}
