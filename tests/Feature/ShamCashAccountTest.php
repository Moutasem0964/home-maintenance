<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShamCashAccountTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_number' => '1234567890123456',
            'account_holder_name' => 'محمد المهندس',
        ], $overrides);
    }

    public function test_a_technician_can_save_their_sham_cash_account(): void
    {
        $tech = Technician::factory()->active()->create();
        /** @var User $user */
        $user = $tech->user;

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/technician/sham-cash-account', $this->payload())
            ->assertOk()
            ->assertJsonPath('data.has_sham_cash_account', true)
            ->assertJsonPath('data.sham_cash_last4', '3456')
            ->assertJsonPath('data.sham_cash_name', 'محمد المهندس');

        $this->assertSame('1234567890123456', $tech->refresh()->sham_cash_number); // decrypted by the cast
    }

    public function test_the_account_number_must_be_16_digits(): void
    {
        $tech = Technician::factory()->active()->create();

        $this->actingAs($tech->user, 'sanctum')
            ->putJson('/api/technician/sham-cash-account', $this->payload(['account_number' => '12345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_number']);
    }

    public function test_a_non_technician_cannot_save_an_account(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/technician/sham-cash-account', $this->payload())
            ->assertForbidden();
    }
}
