<?php

namespace App\Http\Requests\Quote;

use App\Enums\PartClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'labor_cost' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            'warranty_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'justification' => ['nullable', 'string', 'max:1000'],
            'parts' => ['present', 'array'],
            'parts.*.name' => ['required', 'string', 'max:255'],
            'parts.*.price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'parts.*.classification' => ['required', Rule::enum(PartClassification::class)],
            // The actual photo of the part (uploaded), stored privately and served via a
            // streaming route — replaces the old client-supplied image_url string.
            'parts.*.image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
