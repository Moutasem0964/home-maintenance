<?php

namespace App\Services\Realtime;

use App\Contracts\CustomTokenMinter;

/**
 * Local/testing driver: returns a deterministic placeholder token instead of calling Firebase,
 * so the endpoint works offline and tests never need credentials. Never use in production —
 * a real Firebase custom token is required for signInWithCustomToken() to succeed.
 */
class LocalCustomTokenMinter implements CustomTokenMinter
{
    public function mint(string $uid, array $claims = []): string
    {
        return 'local-custom-token:'.$uid;
    }
}
