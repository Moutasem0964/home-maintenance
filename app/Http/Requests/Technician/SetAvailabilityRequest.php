<?php

namespace App\Http\Requests\Technician;

use Illuminate\Foundation\Http\FormRequest;

class SetAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A technician can only be dispatched if we know where they are, so a
        // location is mandatory when going available and optional when going offline.
        return [
            'is_available' => ['required', 'boolean'],
            'current_lat' => ['required_if:is_available,true', 'nullable', 'numeric', 'between:-90,90'],
            'current_lng' => ['required_if:is_available,true', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
