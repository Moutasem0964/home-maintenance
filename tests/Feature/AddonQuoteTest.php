<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuotePart;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\QuoteService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AddonQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AppSettingSeeder::class);
        Storage::fake('local');
    }

    /** @return array{0: User, 1: Order, 2: Technician} — funded client + in-progress order + tech. */
    private function inProgressOrder(string $balance = '1000.00'): array
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, $balance, 'seed-'.$client->id);

        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
            'commission_rate' => '0.1000',
            'arrived_at' => now(),
        ]);

        return [$client->refresh(), $order, $tech];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'labor_cost' => '80.00',
            'parts' => [
                ['name' => 'Extra valve', 'price' => '20.00', 'classification' => 'standard', 'image' => UploadedFile::fake()->image('p.jpg')],
            ],
        ], $overrides);
    }

    private function addonQuote(Order $order, Technician $tech): Quote
    {
        $quote = Quote::factory()->create([
            'order_id' => $order->id, 'technician_id' => $tech->id,
            'type' => QuoteType::Addon, 'labor_cost' => '100.00',
            'status' => QuoteStatus::Pending, 'expires_at' => now()->addHours(24),
        ]);
        QuotePart::factory()->create(['quote_id' => $quote->id, 'price' => '50.00']);

        return $quote;
    }

    public function test_technician_sends_an_addon_quote_mid_job(): void
    {
        [, $order, $tech] = $this->inProgressOrder();

        $this->actingAs($tech->user, 'sanctum')
            ->post("/api/orders/{$order->id}/quotes/addon", $this->payload(), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.type', 'addon');

        $this->assertDatabaseHas('quotes', ['order_id' => $order->id, 'type' => 'addon', 'status' => 'pending']);
        $this->assertDatabaseHas('notifications', ['user_id' => $order->client_id, 'title' => 'وصل عرض سعر إضافي']);
    }

    public function test_addon_is_only_allowed_while_in_progress(): void
    {
        [, $order, $tech] = $this->inProgressOrder();
        $order->update(['status' => OrderStatus::Accepted]);

        $this->actingAs($tech->user, 'sanctum')
            ->post("/api/orders/{$order->id}/quotes/addon", $this->payload(), ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_only_the_assigned_tech_can_send_an_addon(): void
    {
        [, $order] = $this->inProgressOrder();
        $other = Technician::factory()->active()->create();

        $this->actingAs($other->user, 'sanctum')
            ->post("/api/orders/{$order->id}/quotes/addon", $this->payload(), ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_cannot_send_an_addon_while_a_quote_is_pending(): void
    {
        [, $order, $tech] = $this->inProgressOrder();
        $this->addonQuote($order, $tech); // an outstanding pending quote

        $this->actingAs($tech->user, 'sanctum')
            ->post("/api/orders/{$order->id}/quotes/addon", $this->payload(), ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_approving_an_addon_holds_extra_funds_and_keeps_the_order_in_progress(): void
    {
        [$client, $order, $tech] = $this->inProgressOrder('1000.00');
        $quote = $this->addonQuote($order, $tech); // total 150

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(QuoteStatus::Approved, $quote->refresh()->status);
        $wallet = $client->wallet()->firstOrFail();
        $this->assertSame(850.0, (float) $wallet->available_balance);
        $this->assertSame(150.0, (float) $wallet->held_balance);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id, 'type' => 'repair', 'status' => 'held', 'amount' => '150.00',
        ]);
    }

    public function test_rejecting_an_addon_leaves_the_order_running(): void
    {
        [$client, $order, $tech] = $this->inProgressOrder();
        $quote = $this->addonQuote($order, $tech);

        $this->actingAs($client, 'sanctum')->postJson("/api/quotes/{$quote->id}/reject")
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(QuoteStatus::Rejected, $quote->refresh()->status);
        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    public function test_an_expired_addon_lapses_without_closing_the_order(): void
    {
        [, $order, $tech] = $this->inProgressOrder();
        $quote = $this->addonQuote($order, $tech);
        $quote->update(['expires_at' => now()->subMinute()]);

        app(QuoteService::class)->expireStaleQuotes();

        $this->assertSame(QuoteStatus::Expired, $quote->refresh()->status);
        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }
}
