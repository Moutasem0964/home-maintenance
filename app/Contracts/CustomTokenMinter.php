<?php

namespace App\Contracts;

interface CustomTokenMinter
{
    /**
     * Mint a Firebase custom token the client exchanges via signInWithCustomToken()
     * to authenticate against the Realtime Database.
     *
     * @param  array<string, mixed>  $claims  extra auth-token claims (e.g. role, admin)
     */
    public function mint(string $uid, array $claims = []): string;
}
