<?php

namespace Tests\Feature;

use App\Enums\TechnicianStatus;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_requires_authentication(): void
    {
        $technician = Technician::factory()->create();

        $this->postJson("/api/admin/technicians/{$technician->id}/approve")->assertUnauthorized();
    }

    public function test_admin_can_approve_a_pending_technician(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = Technician::factory()->create(); // pending

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/technicians/{$technician->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(TechnicianStatus::Active, $technician->refresh()->status);
    }

    public function test_a_non_admin_cannot_approve(): void
    {
        $client = User::factory()->create(); // role client
        $technician = Technician::factory()->create();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/admin/technicians/{$technician->id}/approve")
            ->assertForbidden();

        $this->assertSame(TechnicianStatus::Pending, $technician->refresh()->status);
    }
}
