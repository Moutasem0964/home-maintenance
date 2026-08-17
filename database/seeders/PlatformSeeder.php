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
        // Keyed on the role (there is exactly one platform account, resolved via sole()), so an
        // existing account is matched regardless of its stored phone — no risk of a duplicate.
        // The phone is stored E.164, consistent with how real users are saved.
        $platformUser = User::firstOrCreate(
            ['role' => UserRole::Platform],
            [
                'phone' => '+963999999999',
                'name' => 'حساب المنصة',
                'password' => Random::generate(40), // hashed by cast
            ],
        );
        Wallet::firstOrCreate(['user_id' => $platformUser->id]);
    }
}
