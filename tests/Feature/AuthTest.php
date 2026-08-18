<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** Run start → capture code → verify, returning the single-use registration ticket. */
    private function verifyForRegister(string $phone = '0912345678'): string
    {
        $this->postJson('/api/auth/register/start', ['phone' => $phone])->assertOk();

        $normalized = '+9639'.substr(preg_replace('/\D/', '', $phone), -8);
        $code = $this->sms->lastCodeFor($normalized);

        return (string) $this->postJson('/api/auth/register/verify', ['phone' => $phone, 'code' => $code])
            ->assertOk()
            ->json('ticket');
    }

    /** Full three-step client registration: start → verify → register/client. */
    private function registerFlow(string $phone = '0912345678', string $name = 'Test Client', string $password = 'Password123'): array
    {
        $ticket = $this->verifyForRegister($phone);
        $normalized = '+9639'.substr(preg_replace('/\D/', '', $phone), -8);

        $response = $this->postJson('/api/auth/register/client', [
            'phone' => $phone,
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $password,
            'ticket' => $ticket,
        ]);

        return [$response, $normalized];
    }

    // ── registration ────────────────────────────────────────────────────────

    public function test_client_registration_stores_an_optional_profile_photo(): void
    {
        Storage::fake('public');
        $ticket = $this->verifyForRegister();

        $response = $this->post('/api/auth/register/client', [
            'phone' => '0912345678',
            'name' => 'Photo Client',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ticket' => $ticket,
            'profile_photo' => UploadedFile::fake()->image('me.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $user = User::where('phone', '+963912345678')->firstOrFail();
        $this->assertNotNull($user->profile_image_url);
        // Avatars live on the public disk now, and the resource returns a full URL.
        Storage::disk('public')->assertExists($user->profile_image_url);
        $response->assertJsonPath('user.profile_image_url', Storage::disk('public')->url($user->profile_image_url));
    }

    public function test_the_profile_photo_is_optional_and_defaults_to_null(): void
    {
        [$response, $normalized] = $this->registerFlow();
        $response->assertCreated();

        $this->assertNull(User::where('phone', $normalized)->firstOrFail()->profile_image_url);
    }

    public function test_a_non_image_profile_photo_is_rejected(): void
    {
        Storage::fake('local');
        $ticket = $this->verifyForRegister();

        $this->post('/api/auth/register/client', [
            'phone' => '0912345678',
            'name' => 'Bad Photo',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ticket' => $ticket,
            'profile_photo' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)
            ->assertJsonValidationErrors(['profile_photo']);
    }

    public function test_register_start_sends_a_code(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678'])
            ->assertOk();

        $this->assertTrue($this->sms->sentTo('+963912345678') || $this->sms->sentTo('+9639'.substr('0912345678', -8)));
    }

    public function test_register_verify_returns_a_ticket_without_creating_a_user(): void
    {
        $ticket = $this->verifyForRegister();

        $this->assertNotEmpty($ticket);
        $this->assertDatabaseCount('users', 0); // verify alone must not create the account
    }

    public function test_register_client_creates_verified_client_with_wallet_and_token(): void
    {
        [$response, $phone] = $this->registerFlow();

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'phone', 'role']]);

        $user = User::where('phone', $phone)->firstOrFail();
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue($user->wallet()->exists());
    }

    public function test_register_client_ignores_a_role_field_in_the_payload(): void
    {
        $ticket = $this->verifyForRegister();

        $this->postJson('/api/auth/register/client', [
            'phone' => '0912345678',
            'name' => 'A',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ticket' => $ticket,
            'role' => 'admin', // must be ignored
        ])->assertCreated();

        $this->assertSame(UserRole::Client, User::where('phone', '+963912345678')->first()?->role);
    }

    public function test_register_verify_rejects_a_wrong_code(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678'])->assertOk();

        $this->postJson('/api/auth/register/verify', [
            'phone' => '0912345678',
            'code' => '0000',
        ])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_client_rejects_an_invalid_ticket(): void
    {
        $this->verifyForRegister(); // a real ticket exists, but we present a bogus one

        $this->postJson('/api/auth/register/client', [
            'phone' => '0912345678',
            'name' => 'A',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'ticket' => 'not-a-real-ticket',
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_start_rejects_a_malformed_phone(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '12345'])
            ->assertStatus(422);
    }

    public function test_register_start_rejects_a_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '+963912345678']);

        $this->postJson('/api/auth/register/start', ['phone' => '0912345678'])
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

    /** forgot → capture code → verify, returning the single-use reset ticket. */
    private function verifyForReset(string $phone = '0912345678'): string
    {
        $normalized = '+9639'.substr(preg_replace('/\D/', '', $phone), -8);

        $this->postJson('/api/auth/password/forgot', ['phone' => $phone])->assertOk();
        $code = $this->sms->lastCodeFor($normalized);

        return (string) $this->postJson('/api/auth/password/verify', ['phone' => $phone, 'code' => $code])
            ->assertOk()
            ->json('ticket');
    }

    public function test_password_reset_updates_password_and_revokes_sessions(): void
    {
        $user = User::factory()->verified()->create(['phone' => '+963912345678', 'password' => 'OldPass123']);
        $user->createToken('mobile'); // an existing session

        $ticket = $this->verifyForReset();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '0912345678',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
            'ticket' => $ticket,
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

    public function test_password_verify_rejects_a_wrong_code(): void
    {
        User::factory()->verified()->create(['phone' => '+963912345678', 'password' => 'OldPass123']);
        $this->postJson('/api/auth/password/forgot', ['phone' => '0912345678'])->assertOk();

        $this->postJson('/api/auth/password/verify', [
            'phone' => '0912345678',
            'code' => '0000',
        ])->assertStatus(422);
    }

    public function test_password_reset_rejects_an_invalid_ticket(): void
    {
        User::factory()->verified()->create(['phone' => '+963912345678', 'password' => 'OldPass123']);
        $this->verifyForReset(); // a real ticket exists, but we present a bogus one

        $this->postJson('/api/auth/password/reset', [
            'phone' => '0912345678',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
            'ticket' => 'not-a-real-ticket',
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket']);

        // Password unchanged → old credentials still work.
        $this->postJson('/api/auth/login', ['phone' => '0912345678', 'password' => 'OldPass123'])->assertOk();
    }

    public function test_reset_ticket_is_single_use(): void
    {
        User::factory()->verified()->create(['phone' => '+963912345678', 'password' => 'OldPass123']);
        $ticket = $this->verifyForReset();

        // First reset succeeds and consumes the ticket.
        $this->postJson('/api/auth/password/reset', [
            'phone' => '0912345678',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
            'ticket' => $ticket,
        ])->assertOk();

        // Replaying the same ticket must fail — single-use.
        $this->postJson('/api/auth/password/reset', [
            'phone' => '0912345678',
            'password' => 'Another123',
            'password_confirmation' => 'Another123',
            'ticket' => $ticket,
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket']);
    }

    public function test_register_start_enforces_the_resend_cooldown(): void
    {
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678'])->assertOk();
        $this->postJson('/api/auth/register/start', ['phone' => '0912345678'])->assertStatus(429);
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
