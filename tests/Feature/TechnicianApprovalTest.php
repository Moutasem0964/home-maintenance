<?php

namespace Tests\Feature;

use App\Enums\TechnicianStatus;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
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

    public function test_admin_can_suspend_a_technician_to_probation(): void
    {
        $this->seed(AppSettingSeeder::class);
        $admin = User::factory()->admin()->create();
        $technician = Technician::factory()->active()->create(['is_available' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/technicians/{$technician->id}/suspend")
            ->assertOk()
            ->assertJsonPath('data.status', 'probation');

        $technician->refresh();
        $this->assertSame(TechnicianStatus::Probation, $technician->status);
        $this->assertNotNull($technician->daily_order_limit);
        $this->assertFalse((bool) $technician->is_available);
    }

    public function test_admin_can_ban_a_technician(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = Technician::factory()->active()->create(['is_available' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/technicians/{$technician->id}/ban")
            ->assertOk()
            ->assertJsonPath('data.status', 'banned');

        $technician->refresh();
        $this->assertSame(TechnicianStatus::Banned, $technician->status);
        $this->assertFalse((bool) $technician->is_available);
    }

    public function test_a_non_admin_cannot_suspend_or_ban(): void
    {
        $client = User::factory()->create();
        $technician = Technician::factory()->active()->create();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/admin/technicians/{$technician->id}/suspend")->assertForbidden();
        $this->actingAs($client, 'sanctum')
            ->postJson("/api/admin/technicians/{$technician->id}/ban")->assertForbidden();

        $this->assertSame(TechnicianStatus::Active, $technician->refresh()->status);
    }
}
