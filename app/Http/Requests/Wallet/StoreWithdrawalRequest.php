<?php

namespace App\Http\Requests\Wallet;

use App\Enums\WithdrawalMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'method' => ['required', Rule::enum(WithdrawalMethod::class)],
            // Where to send the money (account number / wallet id). Stored encrypted.
            'destination_details' => ['required', 'string', 'max:500'],
        ];
    }
}
