<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveNoShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['dismissed', 'confirmed'])],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
