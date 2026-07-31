<?php

// Defaults per SRS UC-21 (4-digit code, 5-min validity, 3 attempts, 15-min lockout).
return [
    'length' => (int) env('OTP_LENGTH', 4),
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),
    'lockout_minutes' => (int) env('OTP_LOCKOUT_MINUTES', 15),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),

    // TEMPORARY: when true, register/start returns the OTP in the response so the
    // frontend can test without SMS. Keep false in real production. Remove once the
    // real SMS driver is wired.
    'expose_code' => (bool) env('OTP_EXPOSE_CODE', false),
];
