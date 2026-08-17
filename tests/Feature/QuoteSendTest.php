<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuotePart;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class QuoteSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
        Storage::fake('local');
    }

    /** @return array{0: Order, 1: Technician} */
    private function acceptedOrder(?float $guidePrice = 100.0): array
    {
        $category = ServiceCategory::factory()->create(['guide_price' => $guidePrice]);
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'service_category_id' => $category->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::Accepted,
            'arrived_at' => now(), // tech is on-site; quoting is unlocked
        ]);

        return [$order, $tech];
    }

    /** A quote part carrying a real uploaded photo. @param array<string, mixed> $overrides @return array<string, mixed> */
    private function part(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Valve',
            'price' => '20.00',
            'classification' => 'standard',
            'image' => UploadedFile::fake()->image('part.jpg'),
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'labor_cost' => '80.00',
            'warranty_days' => 30,
            'parts' => [$this->part()],
        ], $overrides);
    }

    /** Multipart POST — part photos are files, so this can't ride on postJson. */
    private function send(?User $as, Order $order, array $overrides = []): TestResponse
    {
        $test = $as !== null ? $this->actingAs($as, 'sanctum') : $this;

        return $test->post("/api/orders/{$order->id}/quotes", $this->payload($overrides), ['Accept' => 'application/json']);
    }

    public function test_sending_a_quote_requires_authentication(): void
    {
        [$order] = $this->acceptedOrder();

        $this->send(null, $order)->assertUnauthorized();
    }

    public function test_cannot_send_a_quote_before_marking_arrival(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        $order->update(['arrived_at' => null]); // accepted but not yet on-site

        $this->send($tech->user()->firstOrFail(), $order)->assertStatus(409);
    }

    public function test_only_the_assigned_technician_can_send_a_quote(): void
    {
        [$order] = $this->acceptedOrder();
        $otherTech = Technician::factory()->active()->create();

        $this->send($otherTech->user()->firstOrFail(), $order)->assertForbidden();
        $this->send(User::factory()->create(), $order)->assertForbidden();
    }

    public function test_cannot_quote_an_order_that_is_not_accepted(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        $order->update(['status' => OrderStatus::InProgress]);

        $this->send($tech->user()->firstOrFail(), $order)->assertStatus(409);
    }

    public function test_technician_sends_an_initial_quote_with_parts(): void
    {
        [$order, $tech] = $this->acceptedOrder();

        $response = $this->send($tech->user()->firstOrFail(), $order)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.type', 'initial')
            ->assertJsonPath('data.total', '100.00');

        $this->assertDatabaseHas('quotes', ['order_id' => $order->id, 'technician_id' => $tech->id, 'status' => 'pending']);
        $this->assertDatabaseHas('quote_parts', ['name' => 'Valve', 'classification' => 'standard']);

        // The uploaded photo is stored privately, and the response still hands back a loadable image_url.
        $part = QuotePart::firstOrFail();
        Storage::disk('local')->assertExists($part->image_url);
        $response->assertJsonPath('data.parts.0.image_url', url("/api/quote-parts/{$part->id}/image"));
    }

    public function test_cannot_send_a_second_pending_quote(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        Quote::factory()->create(['order_id' => $order->id, 'technician_id' => $tech->id, 'status' => QuoteStatus::Pending]);

        $this->send($tech->user()->firstOrFail(), $order)->assertStatus(409);
    }

    public function test_a_price_anomaly_requires_a_justification(): void
    {
        [$order, $tech] = $this->acceptedOrder(100.0); // guide 100 × 2.0 → threshold 200

        $this->send($tech->user()->firstOrFail(), $order, ['labor_cost' => '500.00', 'parts' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['justification']);
    }

    public function test_an_anomalous_quote_passes_with_a_justification(): void
    {
        [$order, $tech] = $this->acceptedOrder(100.0);

        $this->send($tech->user()->firstOrFail(), $order, [
            'labor_cost' => '500.00', 'parts' => [], 'justification' => 'Rare part, full-day job.',
        ])->assertCreated();
    }

    public function test_each_part_requires_an_image(): void
    {
        [$order, $tech] = $this->acceptedOrder();

        $this->send($tech->user()->firstOrFail(), $order, [
            'parts' => [['name' => 'Valve', 'price' => '20.00', 'classification' => 'standard']],
        ])->assertStatus(422)->assertJsonValidationErrors(['parts.0.image']);
    }

    public function test_the_part_photo_streams_to_a_participant_and_is_denied_to_outsiders(): void
    {
        [$order, $tech] = $this->acceptedOrder();
        $this->send($tech->user()->firstOrFail(), $order)->assertCreated();
        $part = QuotePart::firstOrFail();

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->get("/api/quote-parts/{$part->id}/image")->assertOk();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->get("/api/quote-parts/{$part->id}/image")->assertForbidden();
    }
}
