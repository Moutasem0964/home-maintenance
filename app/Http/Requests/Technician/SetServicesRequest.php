<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'service_category_ids' => ['required', 'array', 'min:1'],
            'service_category_ids.*' => [
                'integer',
                Rule::exists('service_categories', 'id')->where('is_active', true),
            ],
        ];
    }
}
