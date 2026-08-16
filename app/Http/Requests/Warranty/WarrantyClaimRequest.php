<?php

namespace App\Http\Requests\Warranty;

use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;

class WarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxDays = (int) AppSetting::get('scheduled_max_days', 10);

        return [
            // The client picks the revisit time; the original tech is booked into it.
            'scheduled_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now()->addDays($maxDays)->toDateTimeString()],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
