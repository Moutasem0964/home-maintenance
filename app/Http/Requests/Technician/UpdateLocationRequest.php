<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_lat' => ['required', 'numeric', 'between:-90,90'],
            'current_lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
