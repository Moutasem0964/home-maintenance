<?php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class ApproveDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Optional: the admin corrects the amount to what the receipt actually shows.
            'amount' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }
}
