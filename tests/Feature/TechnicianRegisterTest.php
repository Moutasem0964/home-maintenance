<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Enums\TechnicianStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

class TechnicianRegisterTest extends TestCase
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

    private string $phone = '0913333333';

    /** start → capture code → verify, returning the single-use registration ticket. */
    private function verifiedTicket(): string
    {
        $this->postJson('/api/auth/register/start', ['phone' => $this->phone])->assertOk();
        $code = (string) $this->sms->lastCodeFor('+9639'.substr($this->phone, -8));

        return (string) $this->postJson('/api/auth/register/verify', ['phone' => $this->phone, 'code' => $code])
            ->assertOk()
            ->json('ticket');
    }

    public function test_registration_creates_a_pending_technician_with_wallet(): void
    {
        $ticket = $this->verifiedTicket();

        $this->postJson('/api/auth/register/technician', [
            'phone' => $this->phone,
            'name' => 'Tech Guy',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'charter_accepted' => true,
            'ticket' => $ticket,
        ])->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'role']]);

        $user = User::where('phone', '+9639'.substr($this->phone, -8))->firstOrFail();
        $this->assertSame(UserRole::Technician, $user->role);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue($user->wallet()->exists());

        $technician = $user->technician()->firstOrFail();
        $this->assertSame(TechnicianStatus::Pending, $technician->status);
        $this->assertNotNull($technician->charter_accepted_at);
    }

    public function test_registration_requires_charter_acceptance(): void
    {
        $ticket = $this->verifiedTicket();

        $this->postJson('/api/auth/register/technician', [
            'phone' => $this->phone,
            'name' => 'Tech Guy',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'charter_accepted' => false,
            'ticket' => $ticket,
        ])->assertStatus(422)->assertJsonValidationErrors(['charter_accepted']);

        $this->assertDatabaseCount('technicians', 0);
    }

    public function test_registration_rejects_an_invalid_ticket(): void
    {
        $this->verifiedTicket(); // a real ticket exists, but we present a bogus one

        $this->postJson('/api/auth/register/technician', [
            'phone' => $this->phone,
            'name' => 'Tech Guy',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'charter_accepted' => true,
            'ticket' => 'not-a-real-ticket',
        ])->assertStatus(422)->assertJsonValidationErrors(['ticket']);

        $this->assertDatabaseCount('technicians', 0);
    }
}
