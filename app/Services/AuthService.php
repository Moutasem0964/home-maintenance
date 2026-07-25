<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class AuthService
{
    /**
     * Create a verified client account and its wallet in one transaction.
     * Role is fixed to Client here — never taken from request input.
     */
    public function registerClient(string $phone, string $name, string $password): User
    {
        return DB::transaction(function () use ($phone, $name, $password) {
            $user = User::create([
                'name' => $name,
                'phone' => $phone,
                'password' => $password,
                'role' => UserRole::Client,
            ]);

            // Not mass-assignable (server-set only) — assign directly.
            $user->phone_verified_at = now();
            $user->save();

            Wallet::create(['user_id' => $user->id]);

            return $user;
        });
    }

    public function issueToken(User $user): string
    {
        return $user->createToken('mobile')->plainTextToken;
    }
}
