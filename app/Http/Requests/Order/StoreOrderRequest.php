<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderType;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        $maxDays = (int) AppSetting::get('scheduled_max_days', 10);

        return [
            'operation_id' => ['required', 'string', 'max:64'],
            'service_category_id' => [
                'required', 'integer',
                Rule::exists('service_categories', 'id')->where('is_active', true),
            ],
            'address_id' => [
                'required', 'integer',
                Rule::exists('addresses', 'id')->where('user_id', $user->id),
            ],
            'type' => ['required', Rule::enum(OrderType::class)],
            'scheduled_at' => ['required_if:type,scheduled', 'nullable', 'date', 'after:now', 'before_or_equal:'.now()->addDays($maxDays)->toDateTimeString()],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
