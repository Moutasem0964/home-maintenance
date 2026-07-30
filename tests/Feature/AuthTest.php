<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        // Route the OTP through an in-memory sender we can read the code from.
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    /** Run start → capture code → verify, returning the response. */
    private function registerFlow(string $phone = '0912345678', string $name = 'Test Client', string $password = 'Password123'): array
    {
        $this->postJson('/api/auth/register/start', ['phone' => $phone, 'name' => $name])->assertOk();

        $normalized = '+9639'.substr(preg_replace('/\D/', '', $phone), -8);
        $code = $this->sms->lastCodeFor($normalized);

        $response = $this->postJson('/api/auth/register/verify', [
            'phone' => $phone,
            'code' => $code,
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        return [$response, $normalized];
    }

    // ── registration ────────────────────────────────────────────────────────

    public function test_register_start_sends_a_code(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678', 'name' => 'A'])
            ->assertOk();

        $this->assertTrue($this->sms->sentTo('+963912345678') || $this->sms->sentTo('+9639'.substr('0912345678', -8)));
    }

    public function test_register_verify_creates_verified_client_with_wallet_and_token(): void
    {
        [$response, $phone] = $this->registerFlow();

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'phone', 'role']]);

        $user = User::where('phone', $phone)->firstOrFail();
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue($user->wallet()->exists());
    }

    public function test_register_verify_ignores_a_role_field_in_the_payload(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678', 'name' => 'A'])->assertOk();
        $code = $this->sms->lastCodeFor('+9639'.substr('0912345678', -8));

        $this->postJson('/api/auth/register/verify', [
            'phone' => '0912345678',
            'code' => $code,
            'name' => 'A',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'admin', // must be ignored
        ])->assertCreated();

        $this->assertSame(UserRole::Client, User::where('phone', '+963912345678')->first()?->role);
    }

    public function test_register_verify_rejects_a_wrong_code(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678', 'name' => 'A'])->assertOk();

        $this->postJson('/api/auth/register/verify', [
            'phone' => '0912345678',
            'code' => '0000',
            'name' => 'A',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_start_rejects_a_malformed_phone(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '12345', 'name' => 'A'])
            ->assertStatus(422);
    }

    public function test_register_start_rejects_a_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '+963912345678']);

        $this->postJson('/api/auth/register/start', ['phone' => '0912345678', 'name' => 'A'])
            ->assertStatus(422);
    }

    // ── login ────────────────────────────────────────────────────────────────

    public function test_login_succeeds_for_a_verified_user(): void
    {
        User::factory()->verified()->create([
            'phone' => '+963912345678',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->verified()->create([
            'phone' => '+963912345678',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'wrong'])
            ->assertStatus(422);
    }

    public function test_login_forbidden_for_banned_user(): void
    {
        User::factory()->verified()->banned()->create([
            'phone' => '+963912345678',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'Password123'])
            ->assertStatus(403);
    }

    public function test_login_forbidden_for_unverified_user(): void
    {
        User::factory()->create([ // no ->verified()
            'phone' => '+963912345678',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'Password123'])
            ->assertStatus(403);
    }

    // ── password reset (UC-21) ───────────────────────────────────────────────

    public function test_password_reset_updates_password_and_revokes_sessions(): void
    {
        $user = User::factory()->verified()->create(['phone' => '+963912345678', 'password' => 'OldPass123']);
        $user->createToken('mobile'); // an existing session

        $this->postJson('/api/auth/password/forgot', ['phone' => '0912345678'])->assertOk();
        $code = $this->sms->lastCodeFor('+963912345678');

        $this->postJson('/api/auth/password/reset', [
            'phone' => '0912345678',
            'code' => $code,
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count(), 'old sessions must be revoked');
        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'NewPass123'])->assertOk();
        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'OldPass123'])->assertStatus(422);
    }

    public function test_password_forgot_does_not_reveal_unknown_phone(): void
    {
        $this->postJson('/api/auth/password/forgot', ['phone' => '0912345678'])->assertOk();

        $this->assertFalse($this->sms->sentTo('+963912345678'));
    }

    public function test_password_forgot_does_not_leak_existence_via_throttling(): void
    {
        User::factory()->verified()->create(['phone' => '+963912345678']);

        // Second call hits the resend cooldown; it must still be 200 (same as an unknown phone),
        // otherwise a 429 would reveal that the account exists.
        $this->postJson('/api/auth/password/forgot', ['phone' => '0912345678'])->assertOk();
        $this->postJson('/api/auth/password/forgot', ['phone' => '0912345678'])->assertOk();
    }

    public function test_password_reset_rejects_a_wrong_code(): void
    {
        User::factory()->verified()->create(['phone' => '+963912345678', 'password' => 'OldPass123']);
        $this->postJson('/api/auth/password/forgot', ['phone' => '0912345678'])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '0912345678',
            'code' => '0000',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
        ])->assertStatus(422);
    }

    public function test_register_start_enforces_the_resend_cooldown(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678', 'name' => 'A'])->assertOk();
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678', 'name' => 'A'])->assertStatus(429);
    }

    // ── session ──────────────────────────────────────────────────────────────

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->verified()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->verified()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // The token row is deleted → any future request with it fails auth.
        // (Asserting the DB effect avoids the auth-guard caching within one test process.)
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
