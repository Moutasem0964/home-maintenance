<?php

namespace App\Services;

use App\Enums\BalanceType;
use App\Enums\NotificationCategory;
use App\Enums\TopUpStatus;
use App\Enums\TxnType;
use App\Models\TopUp;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Manual (receipt-backed) wallet top-ups — the production money-IN path when no payment gateway
 * is available. The user transfers cash/bank funds to the platform account, uploads the receipt,
 * and an ADMIN verifies it before the wallet is credited. Money never moves on the user's word:
 * crediting happens only on admin approval, at the amount the admin confirms from the receipt.
 */
class DepositService
{
    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * Record a pending top-up with its receipt. Nothing is credited yet. The transfer reference
     * is unique (top_ups.gateway_reference), so the same receipt can't be submitted twice.
     */
    public function request(User $user, string $amount, string $reference, UploadedFile $receipt): TopUp
    {
        $wallet = $user->wallet()->firstOrFail();

        return TopUp::create([
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'gateway_reference' => $reference,
            'receipt_url' => $receipt->store('topup-receipts', 'local'),
            'status' => TopUpStatus::Pending,
        ]);
    }

    /**
     * Admin approves a pending top-up and credits the wallet with the confirmed amount (defaults
     * to the requested amount). The credit is a single boundary Deposit ledger entry — the cash
     * arrived off-ledger — idempotent on the top-up id.
     */
    public function approve(TopUp $topUp, User $admin, ?string $confirmedAmount = null): TopUp
    {
        return DB::transaction(function () use ($topUp, $admin, $confirmedAmount): TopUp {
            /** @var TopUp $locked */
            $locked = TopUp::whereKey($topUp->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TopUpStatus::Pending) {
                throw new \DomainException('This top-up has already been reviewed.');
            }

            $amount = $confirmedAmount ?? (string) $locked->amount;

            $wallet = $locked->wallet()->lockForUpdate()->firstOrFail();
            $wallet->transactions()->create([
                'type' => TxnType::Deposit,
                'balance_type' => BalanceType::Available,
                'amount' => $amount,
                'reference' => "manual-topup:{$locked->id}",
                'description' => 'Manual wallet top-up (admin-approved)',
            ]);
            $wallet->increaseAvailableBalance($amount);

            $locked->update([
                'amount' => $amount,
                'status' => TopUpStatus::Succeeded,
                'reviewed_by' => $admin->id,
            ]);

            $this->notificationService->notify(
                $wallet->user,
                NotificationCategory::Financial,
                'تم شحن محفظتك',
                'تمت الموافقة على طلب الشحن وإضافة المبلغ إلى محفظتك.',
                $locked,
            );

            return $locked;
        });
    }

    /** Admin declines a pending top-up. Nothing is credited. */
    public function reject(TopUp $topUp, User $admin): TopUp
    {
        return DB::transaction(function () use ($topUp, $admin): TopUp {
            /** @var TopUp $locked */
            $locked = TopUp::whereKey($topUp->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TopUpStatus::Pending) {
                throw new \DomainException('This top-up has already been reviewed.');
            }

            $locked->update(['status' => TopUpStatus::Rejected, 'reviewed_by' => $admin->id]);

            $this->notificationService->notify(
                $locked->wallet->user,
                NotificationCategory::Financial,
                'تم رفض طلب الشحن',
                'تعذّر التحقق من إيصال الشحن ولم تتم إضافة أي مبلغ. يُرجى المحاولة مجدداً.',
                $locked,
            );

            return $locked;
        });
    }
}
