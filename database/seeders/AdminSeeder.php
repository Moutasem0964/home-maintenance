<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Store the phone in E.164 (matches PhoneNumber::normalize) so login — which normalizes
        // the submitted phone before lookup — can actually find the seeded admin.
        $admin = User::firstOrCreate(
            ['phone' => '+963900000000'],
            [
                'name' => 'مدير النظام',
                'password' => 'Admin12345', // hashed by cast; matches the prod admin password — change if it differs
                'role' => UserRole::Admin,
            ],
        );

        // Login rejects unverified accounts; the seeded admin is trusted, so mark it verified
        // (phone_verified_at isn't mass-assignable — force it). Idempotent for an existing admin.
        if ($admin->phone_verified_at === null) {
            $admin->forceFill(['phone_verified_at' => now()])->save();
        }

        Wallet::firstOrCreate(['user_id' => $admin->id]);
    }
}
