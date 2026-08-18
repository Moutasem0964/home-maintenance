<?php

namespace App\Http\Requests\Warranty;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarrantyIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // covered = original orders still under active warranty; claimed = warranty visits already requested.
            'filter' => ['required', Rule::in(['covered', 'claimed'])],
        ];
    }
}
