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
        $admin = User::firstOrCreate(
            ['phone' => '0900000000'],
            [
                'name' => 'System Admin',
                'password' => 'ChangeMe!123', // hashed by cast — change immediately
                'role' => UserRole::Admin,
            ],
        );

        Wallet::firstOrCreate(['user_id' => $admin->id]);
    }
}
