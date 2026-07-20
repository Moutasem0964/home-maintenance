<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Nette\Utils\Random;

class PlatformSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platformUser = User::firstOrCreate(
            ['phone' => '0999999999'],
            [
                'name' => 'Platform User',
                'password' => Random::generate(40), // hashed by cast
                'role' => UserRole::Platform,
            ],
        );
        Wallet::firstOrCreate(['user_id' => $platformUser->id]);
    }
}
