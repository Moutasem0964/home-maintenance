<?php

namespace Tests\Unit;

use App\Exceptions\OtpThrottledException;
use App\Services\OtpService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

/**
 * Executable spec for OtpService (SRS UC-21 / UC-22).
 *
 * RED on purpose: App\Contracts\SmsSender and App\Services\OtpService do not
 * exist yet. Cache-backed, no database — verifies the OTP subsystem in isolation.
 *
 * Contract assumed by these tests:
 *   OtpService::__construct(SmsSender $sms)
 *   OtpService::sendCode(string $phone, string $purpose): void
 *   OtpService::verifyCode(string $phone, string $purpose, string $code): bool
 */
class OtpServiceTest extends TestCase
{
    private const PHONE = '+963911111111';

    private const PURPOSE = 'register';

    private FakeSmsSender $sms;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Deterministic OTP policy regardless of the shipped config file.
        config([
            'otp.length' => 4,
            'otp.ttl_minutes' => 5,
            'otp.max_attempts' => 3,
            'otp.lockout_minutes' => 15,
            'otp.resend_cooldown_seconds' => 60,
        ]);

        $this->sms = new FakeSmsSender;
        $this->otp = new OtpService($this->sms);
    }

    public function test_send_code_dispatches_a_four_digit_sms(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);

        $this->assertTrue($this->sms->sentTo(self::PHONE));
        $this->assertMatchesRegularExpression('/\d{4}/', $this->sms->messages[self::PHONE][0]);
    }

    public function test_correct_code_verifies(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->assertTrue($this->otp->verifyCode(self::PHONE, self::PURPOSE, $code));
    }

    public function test_wrong_code_fails(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
        $code = $this->sms->lastCodeFor(self::PHONE);
        $wrong = $code === '0000' ? '1111' : '0000';

        $this->assertFalse($this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong));
    }

    public function test_code_is_single_use(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->assertTrue($this->otp->verifyCode(self::PHONE, self::PURPOSE, $code));
        // A verified code must not work a second time.
        $this->assertFalse($this->otp->verifyCode(self::PHONE, self::PURPOSE, $code));
    }

    public function test_lockout_after_three_wrong_attempts(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
        $code = $this->sms->lastCodeFor(self::PHONE);
        $wrong = $code === '0000' ? '1111' : '0000';

        $this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong);
        $this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong);
        $this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong);

        // After 3 wrong tries, even the CORRECT code is rejected (proves lockout,
        // not just a wrong-code failure). SRS UC-21: 3 attempts → freeze.
        $this->assertFalse($this->otp->verifyCode(self::PHONE, self::PURPOSE, $code));
    }

    public function test_expired_code_fails(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
        $code = $this->sms->lastCodeFor(self::PHONE);

        // TTL is 5 minutes; jump past it.
        $this->travel(6)->minutes();

        $this->assertFalse($this->otp->verifyCode(self::PHONE, self::PURPOSE, $code));
    }

    public function test_purposes_are_isolated(): void
    {
        // A code issued for registration must not verify a password-reset request.
        $this->otp->sendCode(self::PHONE, 'register');
        $code = $this->sms->lastCodeFor(self::PHONE);

        $this->assertFalse($this->otp->verifyCode(self::PHONE, 'password_reset', $code));
    }

    public function test_resend_is_blocked_during_cooldown(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);

        $this->expectException(OtpThrottledException::class);
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
    }

    public function test_send_is_blocked_during_lockout(): void
    {
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
        $code = $this->sms->lastCodeFor(self::PHONE);
        $wrong = $code === '0000' ? '1111' : '0000';

        $this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong);
        $this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong);
        $this->otp->verifyCode(self::PHONE, self::PURPOSE, $wrong);

        $this->expectException(OtpThrottledException::class);
        $this->otp->sendCode(self::PHONE, self::PURPOSE);
    }
}
