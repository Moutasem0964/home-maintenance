<?php

namespace App\Services;

use App\Enums\BalanceType;
use App\Enums\TopUpStatus;
use App\Enums\TxnType;
use App\Models\TopUp;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Credit a wallet from the payment gateway. Idempotent on gateway_reference:
     * a repeated webhook returns the current wallet without crediting twice.
     */
    public function topUp(User $user, string $amount, string $gatewayReference): Wallet
    {
        return DB::transaction(function () use ($user, $amount, $gatewayReference) {
            $wallet = $user->wallet()->lockForUpdate()->firstOrFail();

            if (TopUp::where('gateway_reference', $gatewayReference)->exists()) {
                return $wallet;
            }

            $topUp = TopUp::create([
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'gateway_reference' => $gatewayReference,
                'status' => TopUpStatus::Succeeded,
            ]);

            $wallet->transactions()->create([
                'type' => TxnType::Deposit,
                'balance_type' => BalanceType::Available,
                'amount' => $amount,
                'reference' => "topup:{$topUp->id}",
                'description' => 'Wallet top-up',
            ]);

            $wallet->increaseAvailableBalance($amount);

            return $wallet;
        });
    }
}
