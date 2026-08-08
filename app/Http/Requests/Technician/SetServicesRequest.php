<?php

namespace App\Http\Requests\Technician;

use App\Rules\LeafServiceCategory;
use Illuminate\Foundation\Http\FormRequest;

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
            'service_category_ids.*' => ['integer', new LeafServiceCategory],
        ];
    }
}
