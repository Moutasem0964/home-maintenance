<?php

namespace App\Services;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function __construct(
        private readonly SmsSender $sms,
    ) {}

    public function sendCode(string $phone, string $purpose): void
    {
        $code = $this->generateCode();

        Cache::put(
            $this->codeKey($phone, $purpose),
            Hash::make($code),
            now()->addMinutes((int) config('otp.ttl_minutes')),
        );

        Cache::forget($this->attemptsKey($phone, $purpose));

        $this->sms->send($phone, $this->buildMessage($code));
    }

    public function verifyCode(string $phone, string $purpose, string $code): bool
    {
        // Check the freeze before anything else, so a correct code is still rejected while locked out.
        if (Cache::has($this->lockoutKey($phone, $purpose))) {
            return false;
        }

        $storedHash = Cache::get($this->codeKey($phone, $purpose));
        if ($storedHash === null) {
            return false;
        }

        if (Hash::check($code, $storedHash)) {
            Cache::forget($this->codeKey($phone, $purpose)); // single-use
            Cache::forget($this->attemptsKey($phone, $purpose));

            return true;
        }

        $attempts = (int) Cache::get($this->attemptsKey($phone, $purpose), 0) + 1;
        Cache::put(
            $this->attemptsKey($phone, $purpose),
            $attempts,
            now()->addMinutes((int) config('otp.ttl_minutes')),
        );

        if ($attempts >= (int) config('otp.max_attempts')) {
            Cache::put(
                $this->lockoutKey($phone, $purpose),
                true,
                now()->addMinutes((int) config('otp.lockout_minutes')),
            );
            Cache::forget($this->codeKey($phone, $purpose));
        }

        return false;
    }

    private function generateCode(): string
    {
        $length = (int) config('otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function buildMessage(string $code): string
    {
        $ttl = (int) config('otp.ttl_minutes');

        return "Home Maintenance verification code: {$code} (valid {$ttl} min).";
    }

    private function codeKey(string $phone, string $purpose): string
    {
        return "otp:code:{$purpose}:{$phone}";
    }

    private function attemptsKey(string $phone, string $purpose): string
    {
        return "otp:attempts:{$purpose}:{$phone}";
    }

    private function lockoutKey(string $phone, string $purpose): string
    {
        return "otp:lockout:{$purpose}:{$phone}";
    }
}
