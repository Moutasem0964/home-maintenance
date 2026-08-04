<?php

namespace App\Http\Requests\Dispute;

use App\Enums\DisputeResolution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'resolution' => ['required', Rule::enum(DisputeResolution::class)],
            'refund_amount' => ['required_if:resolution,partial_refund', 'nullable', 'numeric', 'gt:0'],
        ];
    }
}
