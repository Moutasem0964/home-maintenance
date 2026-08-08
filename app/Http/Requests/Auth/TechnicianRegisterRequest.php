<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class TechnicianRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'unique:users,phone'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'charter_accepted' => ['accepted'],
            'ticket' => ['required', 'string'],
            // KYC uploads (private disk). Images only, capped to protect the VM disk.
            'id_front' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'id_back' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'selfie' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
