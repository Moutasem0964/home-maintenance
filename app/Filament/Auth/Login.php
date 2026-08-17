<?php

namespace App\Filament\Auth;

use App\Support\PhoneNumber;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Phone-based admin login. Our users authenticate by phone (there is no email column),
 * so we swap the default email field for a phone field and normalize it to E.164 before
 * the credential check — exactly like the API login does.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getPhoneFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ]);
    }

    protected function getPhoneFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('رقم الهاتف')
            ->tel()
            ->required()
            ->autofocus()
            ->autocomplete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'phone' => PhoneNumber::normalize($data['phone']),
            'password' => $data['password'],
        ];
    }

    /** Bind the "credentials don't match" error to the phone field (base uses email). */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.phone' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
