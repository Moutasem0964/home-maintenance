<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class VerifyClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
        ];
    }
}
