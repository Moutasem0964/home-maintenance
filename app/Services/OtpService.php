<?php

namespace App\Services;

use App\Contracts\SmsSender;
use App\Exceptions\OtpThrottledException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function __construct(
        private readonly SmsSender $sms,
    ) {}

    public function sendCode(string $phone, string $purpose): string
    {
        // SRS UC-21: no new code while frozen, and not more than once per cooldown.
        if (Cache::has($this->lockoutKey($phone, $purpose))) {
            throw new OtpThrottledException('Too many attempts. Try again later.');
        }

        if (Cache::has($this->cooldownKey($phone, $purpose))) {
            throw new OtpThrottledException('Please wait before requesting another code.');
        }

        $code = $this->generateCode();

        Cache::put(
            $this->codeKey($phone, $purpose),
            Hash::make($code),
            now()->addMinutes((int) config('otp.ttl_minutes')),
        );

        Cache::forget($this->attemptsKey($phone, $purpose));

        Cache::put(
            $this->cooldownKey($phone, $purpose),
            true,
            now()->addSeconds((int) config('otp.resend_cooldown_seconds')),
        );

        $this->sms->send($phone, $this->buildMessage($code));

        return $code;
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

    /**
     * Issue a single-use verification ticket after a successful OTP verify. The plaintext
     * is returned to the client; only a hash is stored, valid for otp.ticket_ttl_minutes.
     * The follow-up action (registration / password reset) redeems it via consumeTicket().
     */
    public function issueTicket(string $phone, string $purpose): string
    {
        $ticket = bin2hex(random_bytes(32));

        Cache::put(
            $this->ticketKey($phone, $purpose),
            Hash::make($ticket),
            now()->addMinutes((int) config('otp.ticket_ttl_minutes')),
        );

        return $ticket;
    }

    /** Redeem a verification ticket. Returns true once, then forgets it (single-use). */
    public function consumeTicket(string $phone, string $purpose, string $ticket): bool
    {
        $storedHash = Cache::get($this->ticketKey($phone, $purpose));
        if ($storedHash === null) {
            return false;
        }

        if (! Hash::check($ticket, $storedHash)) {
            return false;
        }

        Cache::forget($this->ticketKey($phone, $purpose)); // single-use

        return true;
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

        // Syriatel filters the English phrase "verification code" as A2P spam (MTN allows it);
        // the Arabic "رمز التحقق" passes both, so we keep the wording Arabic. (Confirmed by testing.)
        return "Home Maintenance رمز التحقق: {$code} (valid {$ttl} min).";
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

    private function cooldownKey(string $phone, string $purpose): string
    {
        return "otp:cooldown:{$purpose}:{$phone}";
    }

    private function ticketKey(string $phone, string $purpose): string
    {
        return "otp:ticket:{$purpose}:{$phone}";
    }
}
