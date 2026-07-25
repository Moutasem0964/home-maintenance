<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalize a Syrian mobile number to E.164 (+9639XXXXXXXX), or null if invalid.
     * Accepts local (09XXXXXXXX), national (9639...), and international (+9639..., 009639...).
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw); // strip everything but digits (drops +, spaces, -)

        if (str_starts_with($digits, '00963')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $e164 = '963'.substr($digits, 1); // local 09... → 9639...
        } elseif (str_starts_with($digits, '963')) {
            $e164 = $digits;
        } else {
            return null;
        }

        // Valid Syrian mobile: 963 + 9 + 8 more digits.
        return preg_match('/^9639\d{8}$/', $e164) ? '+'.$e164 : null;
    }
}
