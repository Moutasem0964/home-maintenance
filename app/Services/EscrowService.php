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
        private readonly PlatformService $platform,
    ) {}

    private function isReleasable(Order $order): bool
    {
        return match ($order->status) {
            OrderStatus::Completed => ! $order->hasOpenDispute(),
            // Other conditions for releasing funds based on order status
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

            $platform = $this->platform->account();

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
}
