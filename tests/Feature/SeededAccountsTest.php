<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeededAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_freshly_seeded_admin_can_log_in(): void
    {
        $this->seed(AdminSeeder::class);

        $this->postJson('/api/auth/login', ['phone' => '0900000000', 'password' => 'Admin12345'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'role']]);
    }

    public function test_the_seeded_admin_is_stored_e164_and_verified(): void
    {
        $this->seed(AdminSeeder::class);

        $admin = User::where('phone', '+963900000000')->first();
        $this->assertNotNull($admin);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNotNull($admin->phone_verified_at);
    }

    public function test_seeding_the_platform_account_twice_does_not_duplicate_it(): void
    {
        $this->seed(PlatformSeeder::class);
        $this->seed(PlatformSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::Platform)->count());
    }
}
