<?php

namespace App\Services;

use App\Enums\BalanceType;
use App\Enums\NotificationCategory;
use App\Enums\OrderStatus;
use App\Enums\TxnType;
use App\Enums\WithdrawalMethod;
use App\Enums\WithdrawalStatus;
use App\Models\AppSetting;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manual (receipt-backed) technician cash-out — the production money-OUT path when no payment
 * gateway is available. The technician requests a payout, which RESERVES the amount out of their
 * available balance (available -> held) so it can't be double-spent. An admin then either pays
 * the money out externally and uploads the receipt (held -> out, Completed) or declines and
 * returns the reservation (held -> available, Rejected). Money only leaves on admin action.
 */
class WithdrawalService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function request(User $user, string $amount, WithdrawalMethod $method, string $destination): Withdrawal
    {
        /** @var Technician $technician */
        $technician = $user->technician()->firstOrFail();

        return DB::transaction(function () use ($user, $technician, $amount, $method, $destination): Withdrawal {
            $wallet = $user->wallet()->lockForUpdate()->firstOrFail();

            $min = number_format((float) AppSetting::get('min_withdrawal_amount', 100), 2, '.', '');
            if (bccomp($amount, $min, 2) < 0) {
                throw new \DomainException("The minimum withdrawal is {$min}.");
            }

            if (bccomp($wallet->available_balance, $amount, 2) < 0) {
                throw new \DomainException('Insufficient available balance for this withdrawal.');
            }

            if ($this->hasActiveWithdrawal($technician)) {
                throw new \DomainException('You already have a withdrawal in progress.');
            }

            if ($this->hasOpenDispute($technician)) {
                throw new \DomainException('Withdrawals are paused while one of your orders is under dispute.');
            }

            /** @var Withdrawal $withdrawal */
            $withdrawal = Withdrawal::create([
                'technician_id' => $technician->id,
                'amount' => $amount,
                'method' => $method,
                'destination_details' => $destination,
                'status' => WithdrawalStatus::Processing,
            ]);

            // Reserve: move the amount out of available into held.
            $wallet->transactions()->create([
                'type' => TxnType::Hold, 'balance_type' => BalanceType::Available,
                'amount' => bcmul($amount, '-1', 2),
                'reference' => "withdrawal:{$withdrawal->id}:reserve:available",
                'description' => "Reserve funds for withdrawal #{$withdrawal->id}",
            ]);
            $wallet->transactions()->create([
                'type' => TxnType::Hold, 'balance_type' => BalanceType::Held,
                'amount' => $amount,
                'reference' => "withdrawal:{$withdrawal->id}:reserve:held",
                'description' => "Reserve funds for withdrawal #{$withdrawal->id}",
            ]);
            $wallet->decreaseAvailableBalance($amount);
            $wallet->increaseHeldBalance($amount);

            return $withdrawal;
        });
    }

    /** Admin paid the technician externally and uploaded proof: the reserved funds leave the ledger. */
    public function complete(Withdrawal $withdrawal, User $admin, UploadedFile $receipt): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $admin, $receipt): Withdrawal {
            /** @var Withdrawal $locked */
            $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WithdrawalStatus::Processing) {
                throw new \DomainException('This withdrawal has already been resolved.');
            }

            $wallet = $locked->technician->user->wallet()->lockForUpdate()->firstOrFail();

            // Boundary outflow: the held reservation leaves the platform (paid off-ledger).
            $wallet->transactions()->create([
                'type' => TxnType::Withdrawal, 'balance_type' => BalanceType::Held,
                'amount' => bcmul((string) $locked->amount, '-1', 2),
                'reference' => "withdrawal:{$locked->id}:pay",
                'description' => "Withdrawal #{$locked->id} paid out",
            ]);
            $wallet->decreaseHeldBalance((string) $locked->amount);

            $locked->update([
                'status' => WithdrawalStatus::Completed,
                'processed_by' => $admin->id,
                'receipt_url' => $receipt->store('withdrawal-receipts', 'local'),
            ]);

            $this->notificationService->notify(
                $locked->technician->user,
                NotificationCategory::Financial,
                'تم تحويل مستحقاتك',
                'تمت معالجة طلب السحب وتحويل المبلغ إليك.',
                $locked,
            );

            return $locked;
        });
    }

    /** Admin declined the payout: return the reserved funds to the technician's available balance. */
    public function reject(Withdrawal $withdrawal, User $admin): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal, $admin): Withdrawal {
            /** @var Withdrawal $locked */
            $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WithdrawalStatus::Processing) {
                throw new \DomainException('This withdrawal has already been resolved.');
            }

            $wallet = $locked->technician->user->wallet()->lockForUpdate()->firstOrFail();

            // Release the reservation: held -> available.
            $wallet->transactions()->create([
                'type' => TxnType::Reversal, 'balance_type' => BalanceType::Held,
                'amount' => bcmul((string) $locked->amount, '-1', 2),
                'reference' => "withdrawal:{$locked->id}:release:held",
                'description' => "Release reserved funds for rejected withdrawal #{$locked->id}",
            ]);
            $wallet->transactions()->create([
                'type' => TxnType::Reversal, 'balance_type' => BalanceType::Available,
                'amount' => (string) $locked->amount,
                'reference' => "withdrawal:{$locked->id}:release:available",
                'description' => "Release reserved funds for rejected withdrawal #{$locked->id}",
            ]);
            $wallet->decreaseHeldBalance((string) $locked->amount);
            $wallet->increaseAvailableBalance((string) $locked->amount);

            $locked->update(['status' => WithdrawalStatus::Rejected, 'processed_by' => $admin->id]);

            $this->notificationService->notify(
                $locked->technician->user,
                NotificationCategory::Financial,
                'تم رفض طلب السحب',
                'تعذّر تنفيذ طلب السحب وتمت إعادة المبلغ إلى رصيدك المتاح.',
                $locked,
            );

            return $locked;
        });
    }

    private function hasActiveWithdrawal(Technician $technician): bool
    {
        return Withdrawal::query()
            ->where('technician_id', $technician->id)
            ->where('status', WithdrawalStatus::Processing)
            ->exists();
    }

    /** True if the technician has any order with an unresolved dispute (payouts are paused then). */
    private function hasOpenDispute(Technician $technician): bool
    {
        return Order::query()
            ->where('technician_id', $technician->id)
            ->whereNotIn('status', [OrderStatus::Canceled, OrderStatus::Expired])
            ->whereHas('dispute', fn (Builder $q) => $q->whereNull('resolved_at'))
            ->exists();
    }
}
