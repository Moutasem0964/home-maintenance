<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class WaitForPartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
