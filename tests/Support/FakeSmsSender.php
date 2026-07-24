<?php

namespace Tests\Support;

use App\Contracts\SmsSender;

/**
 * Test double for the SMS gateway: records messages in memory instead of
 * sending them, and can extract the OTP code so tests can "read the SMS".
 * Reused by both the OtpService unit test and the auth feature tests.
 */
class FakeSmsSender implements SmsSender
{
    /** @var array<string, list<string>> phone => messages */
    public array $messages = [];

    public function send(string $phone, string $message): void
    {
        $this->messages[$phone][] = $message;
    }

    public function sentTo(string $phone): bool
    {
        return ! empty($this->messages[$phone]);
    }

    /** Pull the 4-digit code out of the last message sent to a phone. */
    public function lastCodeFor(string $phone): ?string
    {
        $last = end($this->messages[$phone]) ?: '';
        preg_match('/\d{4}/', $last, $m);

        return $m[0] ?? null;
    }
}
