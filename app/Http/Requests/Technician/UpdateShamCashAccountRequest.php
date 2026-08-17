<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShamCashAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A Sham Cash account number is exactly 16 digits. Kept as a string so leading
            // zeros survive and it never overflows an integer.
            'account_number' => ['required', 'string', 'digits:16'],
            'account_holder_name' => ['required', 'string', 'max:255'],
        ];
    }
}
