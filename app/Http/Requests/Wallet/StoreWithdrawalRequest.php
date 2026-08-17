<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Just the amount: the payout destination is the technician's saved Sham Cash account,
        // snapshotted onto the withdrawal in WithdrawalService::request().
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }
}
