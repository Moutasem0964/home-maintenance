<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Services\PartsWaitService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartsWaitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class); // parts_wait_max_hours = 72
    }

    /** @return array{0: Order, 1: Technician} */
    private function inProgressOrder(): array
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
        ]);

        return [$order, $tech];
    }

    public function test_wait_requires_authentication(): void
    {
        [$order] = $this->inProgressOrder();

        $this->postJson("/api/orders/{$order->id}/waiting-for-parts")->assertUnauthorized();
    }

    public function test_only_the_assigned_technician_can_pause(): void
    {
        [$order] = $this->inProgressOrder();
        $other = Technician::factory()->active()->create();

        $this->actingAs($other->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/waiting-for-parts")->assertForbidden();
    }

    public function test_technician_pauses_an_in_progress_order(): void
    {
        [$order, $tech] = $this->inProgressOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/waiting-for-parts", ['note' => 'Ordering a compressor'])
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_parts');

        $order->refresh();
        $this->assertSame(OrderStatus::WaitingForParts, $order->status);
        $this->assertNotNull($order->parts_waiting_until);
        $this->assertSame('Ordering a compressor', $order->parts_note);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'waiting_for_parts']);
    }

    public function test_cannot_pause_when_not_in_progress(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create(['technician_id' => $tech->id, 'status' => OrderStatus::Accepted]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/waiting-for-parts")->assertStatus(409);
    }

    public function test_technician_resumes_a_waiting_order(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::WaitingForParts,
            'parts_waiting_until' => now()->addHours(72),
        ]);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $order->refresh();
        $this->assertSame(OrderStatus::InProgress, $order->status);
        $this->assertNull($order->parts_waiting_until);
    }

    public function test_cannot_resume_an_order_that_is_not_waiting(): void
    {
        [$order, $tech] = $this->inProgressOrder();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/orders/{$order->id}/resume")->assertStatus(409);
    }

    public function test_overdue_wait_is_flagged_for_admin_once(): void
    {
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::WaitingForParts,
            'parts_waiting_until' => now()->subHour(), // overdue
        ]);

        $service = app(PartsWaitService::class);
        $this->assertSame(1, $service->flagOverdue());

        $this->assertDatabaseHas('technician_flags', [
            'technician_id' => $tech->id,
            'order_id' => $order->id,
            'reason' => 'parts_delay',
            'status' => 'open',
        ]);
        $this->assertNotNull($order->refresh()->parts_overdue_flagged_at);

        // Idempotent: a second sweep raises no new flag.
        $this->assertSame(0, $service->flagOverdue());
        $this->assertDatabaseCount('technician_flags', 1);
    }

    public function test_a_fresh_wait_is_not_flagged(): void
    {
        $tech = Technician::factory()->active()->create();
        Order::factory()->create([
            'technician_id' => $tech->id,
            'status' => OrderStatus::WaitingForParts,
            'parts_waiting_until' => now()->addHours(10), // still within the window
        ]);

        $this->assertSame(0, app(PartsWaitService::class)->flagOverdue());
        $this->assertDatabaseCount('technician_flags', 0);
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->artisan('parts:flag-overdue')->assertExitCode(0);
    }
}
