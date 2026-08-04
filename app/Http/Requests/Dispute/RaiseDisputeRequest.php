<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RaiseDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DisputeReason::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
