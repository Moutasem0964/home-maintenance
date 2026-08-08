<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\TechnicianStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProbationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class); // promotion_min_orders = 5, promotion_min_rating = 4.0
    }

    /** @return array{0: Technician, 1: User, 2: Collection<int, Order>} */
    private function probationTechWithCompletedOrders(int $count): array
    {
        $client = User::factory()->verified()->create();
        $tech = Technician::factory()->create(['status' => TechnicianStatus::Probation, 'daily_order_limit' => 3]);
        $orders = Order::factory()->count($count)->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::Completed,
        ]);

        return [$tech, $client, $orders];
    }

    public function test_promoted_to_active_after_enough_orders_and_a_good_rating(): void
    {
        [$tech, $client, $orders] = $this->probationTechWithCompletedOrders(5);

        // A 5-star review pushes rating to 5.0 with 5 completed orders → promotion.
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$orders->first()->id}/review", [
            'cleanliness' => 5, 'quality' => 5, 'price_rating' => 5,
        ])->assertCreated();

        $tech->refresh();
        $this->assertSame(TechnicianStatus::Active, $tech->status);
        $this->assertNull($tech->daily_order_limit, 'the daily cap is lifted on promotion');
    }

    public function test_not_promoted_when_rating_is_below_the_threshold(): void
    {
        [$tech, $client, $orders] = $this->probationTechWithCompletedOrders(5);

        // Low review → rating 2.0 < 4.0, so no promotion despite enough orders.
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$orders->first()->id}/review", [
            'cleanliness' => 2, 'quality' => 2, 'price_rating' => 2,
        ])->assertCreated();

        $this->assertSame(TechnicianStatus::Probation, $tech->refresh()->status);
    }

    public function test_not_promoted_with_too_few_completed_orders(): void
    {
        [$tech, $client, $orders] = $this->probationTechWithCompletedOrders(2);

        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$orders->first()->id}/review", [
            'cleanliness' => 5, 'quality' => 5, 'price_rating' => 5,
        ])->assertCreated();

        $this->assertSame(TechnicianStatus::Probation, $tech->refresh()->status);
    }

    public function test_progress_endpoint_reports_remaining_criteria(): void
    {
        [$tech] = $this->probationTechWithCompletedOrders(2);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->getJson('/api/technician/probation-progress')
            ->assertOk()
            ->assertJsonPath('data.status', 'probation')
            ->assertJsonPath('data.completed_orders', 2)
            ->assertJsonPath('data.min_orders', 5)
            ->assertJsonPath('data.orders_remaining', 3);
    }
}
