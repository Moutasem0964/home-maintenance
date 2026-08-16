<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Enums\TechnicianStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        // KYC files land on the private disk — fake it so nothing hits real storage.
        Storage::fake('local');
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

    /**
     * A full multipart registration payload (with the three KYC images).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $ticket, array $overrides = []): array
    {
        return array_merge([
            'phone' => $this->phone,
            'name' => 'Tech Guy',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'charter_accepted' => true,
            'ticket' => $ticket,
            'id_front' => UploadedFile::fake()->image('front.jpg'),
            'id_back' => UploadedFile::fake()->image('back.jpg'),
            'selfie' => UploadedFile::fake()->image('selfie.jpg'),
        ], $overrides);
    }

    public function test_registration_creates_a_pending_technician_with_docs_and_wallet(): void
    {
        $ticket = $this->verifiedTicket();

        $this->post('/api/auth/register/technician', $this->payload($ticket), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'role']]);

        $user = User::where('phone', '+9639'.substr($this->phone, -8))->firstOrFail();
        $this->assertSame(UserRole::Technician, $user->role);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue($user->wallet()->exists());

        $technician = $user->technician()->firstOrFail();
        $this->assertSame(TechnicianStatus::Pending, $technician->status);
        $this->assertNotNull($technician->id_front_url);
        $this->assertNotNull($technician->id_back_url);
        $this->assertNotNull($technician->selfie_url);
        Storage::disk('local')->assertExists($technician->id_front_url);
    }

    public function test_registration_requires_charter_acceptance(): void
    {
        $ticket = $this->verifiedTicket();

        $this->post('/api/auth/register/technician', $this->payload($ticket, ['charter_accepted' => false]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['charter_accepted']);

        $this->assertDatabaseCount('technicians', 0);
    }

    public function test_registration_requires_the_three_documents(): void
    {
        $ticket = $this->verifiedTicket();
        $payload = $this->payload($ticket);
        unset($payload['id_front'], $payload['id_back'], $payload['selfie']);

        $this->post('/api/auth/register/technician', $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_front', 'id_back', 'selfie']);

        $this->assertDatabaseCount('technicians', 0);
    }

    public function test_registration_stores_an_optional_profile_photo(): void
    {
        $ticket = $this->verifiedTicket();

        $this->post('/api/auth/register/technician', $this->payload($ticket, [
            'profile_photo' => UploadedFile::fake()->image('me.jpg'),
        ]), ['Accept' => 'application/json'])->assertCreated();

        $user = User::where('phone', '+9639'.substr($this->phone, -8))->firstOrFail();
        $this->assertNotNull($user->profile_image_url);
        Storage::disk('local')->assertExists($user->profile_image_url);
    }

    public function test_registration_rejects_an_invalid_ticket(): void
    {
        $this->verifiedTicket(); // a real ticket exists, but we present a bogus one

        $this->post('/api/auth/register/technician', $this->payload('not-a-real-ticket'), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ticket']);

        $this->assertDatabaseCount('technicians', 0);
    }
}
