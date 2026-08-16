<?php

namespace App\Services\Realtime;

use App\Contracts\CustomTokenMinter;
use Kreait\Firebase\Contract\Auth;

/** Production driver: mints a real Firebase custom token via the Admin SDK. */
class FirebaseCustomTokenMinter implements CustomTokenMinter
{
    public function __construct(private readonly Auth $auth) {}

    public function mint(string $uid, array $claims = []): string
    {
        return $this->auth->createCustomToken($uid, $claims)->toString();
    }
}
