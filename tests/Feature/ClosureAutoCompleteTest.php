<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClosureService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ClosureAutoCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
    }

    private function closure(): ClosureService
    {
        return app(ClosureService::class);
    }

    /** An in-progress order for which the tech has requested closure, with the given review deadline. */
    private function pendingClosureOrder(?Carbon $expiresAt): Order
    {
        $client = User::factory()->create();
        $tech = Technician::factory()->active()->create();

        return Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
            'closure_code' => $expiresAt !== null ? '1234' : null,
            'closure_expires_at' => $expiresAt,
        ]);
    }

    public function test_auto_completes_an_order_whose_review_window_elapsed(): void
    {
        $order = $this->pendingClosureOrder(now()->subMinute());

        $completed = $this->closure()->autoCompleteStaleClosures();

        $this->assertSame(1, $completed);
        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->dispute_deadline_at);   // 48h held window opens
        $this->assertNull($order->closure_code);             // code burned
        $this->assertNull($order->closure_verified_at);      // never confirmed by a code
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'event_type' => 'closure_auto_completed']);
    }

    public function test_leaves_a_fresh_review_window_alone(): void
    {
        $order = $this->pendingClosureOrder(now()->addMinutes(9));

        $this->assertSame(0, $this->closure()->autoCompleteStaleClosures());
        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    public function test_skips_orders_with_no_closure_requested(): void
    {
        $order = $this->pendingClosureOrder(null); // tech never requested closure

        $this->assertSame(0, $this->closure()->autoCompleteStaleClosures());
        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    public function test_does_not_touch_an_order_that_is_no_longer_in_progress(): void
    {
        $order = $this->pendingClosureOrder(now()->subMinute());
        $order->update(['status' => OrderStatus::Disputed]); // client disputed during the window

        $this->assertSame(0, $this->closure()->autoCompleteStaleClosures());
        $this->assertSame(OrderStatus::Disputed, $order->refresh()->status);
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->artisan('closure:auto-complete')->assertSuccessful();
    }
}
