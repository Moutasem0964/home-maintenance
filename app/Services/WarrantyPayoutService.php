<?php

namespace App\Services;

use App\Enums\BalanceType;
use App\Enums\NotificationCategory;
use App\Enums\OrderEventType;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Enums\QuoteStatus;
use App\Enums\TxnType;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * The platform's warranty guarantee: when a warranty visit is honoured by a SUBSTITUTE
 * (any technician other than the one who did the original job), the platform — not the
 * client — pays that substitute the original job's labor cost out of the platform wallet.
 * The original technician, by contrast, owes the warranty fix for free.
 *
 * The obligation is recorded as a Payment (payer = platform, payee = substitute) so it is
 * durable and idempotent. If the platform wallet is short, the payout stays Pending and an
 * admin is asked to top up; retryPending() settles it once funds arrive.
 */
class WarrantyPayoutService
{
    public function __construct(
        private readonly PlatformService $platformService,
        private readonly NotificationService $notificationService,
    ) {}

    private function idempotencyKey(Order $warranty): string
    {
        return "warranty-payout:{$warranty->id}";
    }

    /**
     * Settle (or record and attempt) the substitute payout for a COMPLETED warranty order.
     * Safe to call more than once: a payout already released is a no-op, and the wallet locks
     * serialize concurrent attempts. Called after a warranty visit reaches Completed and by the
     * retry sweep once the platform wallet is funded.
     */
    public function settle(Order $warranty): void
    {
        if ($warranty->kind !== OrderKind::Warranty || $warranty->status !== OrderStatus::Completed) {
            return;
        }

        /** @var Order|null $parent */
        $parent = $warranty->parentOrder;
        if ($parent === null || $warranty->technician_id === null) {
            return;
        }

        // The original tech honours the warranty for free — only a substitute is paid.
        if ($warranty->technician_id === $parent->technician_id) {
            return;
        }

        $amount = $this->originalLaborCost($parent);
        if (bccomp($amount, '0.00', 2) <= 0) {
            return;
        }

        DB::transaction(function () use ($warranty, $amount): void {
            /** @var Payment|null $existing */
            $existing = $warranty->payments()
                ->where('idempotency_key', $this->idempotencyKey($warranty))
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status === PaymentStatus::Released) {
                return; // already paid
            }

            $platform = $this->platformService->account();
            $platformWallet = $platform->wallet()->lockForUpdate()->firstOrFail();
            $substituteUser = $warranty->technician->user;
            $substituteWallet = $substituteUser->wallet()->lockForUpdate()->firstOrFail();

            $payment = $existing ?? Payment::create([
                'order_id' => $warranty->id,
                'payer_id' => $platform->id,
                'payee_id' => $substituteUser->id,
                'type' => PaymentType::Repair,
                'amount' => $amount,
                'commission_amount' => '0.00',
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $this->idempotencyKey($warranty),
            ]);

            // Not enough in the guarantee fund yet — leave it Pending for the retry sweep.
            if (bccomp($platformWallet->available_balance, $amount, 2) < 0) {
                OrderEvent::create([
                    'order_id' => $warranty->id,
                    'event_type' => OrderEventType::SubstitutePayoutPending,
                ]);

                $this->notificationService->notify(
                    $platform,
                    NotificationCategory::Admin,
                    'محفظة المنصة بحاجة إلى تعبئة',
                    'يوجد مستحق لفني بديل عن زيارة ضمان يتعذّر دفعه — يُرجى تعبئة محفظة المنصة.',
                    $warranty,
                );

                return;
            }

            $platformWallet->transactions()->create([
                'payment_id' => $payment->id,
                'order_id' => $warranty->id,
                'type' => TxnType::Payout,
                'balance_type' => BalanceType::Available,
                'amount' => bcmul($amount, '-1', 2),
                'reference' => "payout:warranty:{$warranty->id}:platform",
                'description' => "Warranty substitute payout for order #{$warranty->id}",
            ]);
            $substituteWallet->transactions()->create([
                'payment_id' => $payment->id,
                'order_id' => $warranty->id,
                'type' => TxnType::Payout,
                'balance_type' => BalanceType::Available,
                'amount' => $amount,
                'reference' => "payout:warranty:{$warranty->id}:tech",
                'description' => "Warranty substitute payout for order #{$warranty->id}",
            ]);

            $platformWallet->decreaseAvailableBalance($amount);
            $substituteWallet->increaseAvailableBalance($amount);

            $payment->update(['status' => PaymentStatus::Released, 'released_at' => now()]);

            OrderEvent::create(['order_id' => $warranty->id, 'event_type' => OrderEventType::SubstitutePaid]);

            $this->notificationService->notify(
                $substituteUser,
                NotificationCategory::Financial,
                'تم استلام دفعة',
                'تم تحرير مستحقاتك عن زيارة الضمان إلى محفظتك.',
                $warranty,
            );
        });
    }

    /**
     * Re-attempt every warranty payout still awaiting funds. Run on a schedule and immediately
     * after an admin tops up the platform wallet. Returns how many payouts were settled.
     */
    public function retryPending(): int
    {
        $pending = Payment::query()
            ->where('type', PaymentType::Repair)
            ->where('status', PaymentStatus::Pending)
            ->where('idempotency_key', 'like', 'warranty-payout:%')
            ->with('order')
            ->lazyById(200);

        $settled = 0;
        foreach ($pending as $payment) {
            /** @var Order|null $order */
            $order = $payment->order;
            if ($order === null) {
                continue;
            }

            $this->settle($order);

            if ($payment->fresh()?->status === PaymentStatus::Released) {
                $settled++;
            }
        }

        return $settled;
    }

    /** The original job's labor cost, taken from the parent order's approved quote. */
    private function originalLaborCost(Order $parent): string
    {
        /** @var Quote|null $quote */
        $quote = $parent->quotes()->where('status', QuoteStatus::Approved)->first();

        return $quote !== null ? number_format((float) $quote->labor_cost, 2, '.', '') : '0.00';
    }
}
