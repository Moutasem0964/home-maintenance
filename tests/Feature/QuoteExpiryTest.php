<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expires_a_stale_quote_and_closes_the_order_inspection_only(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Accepted]);
        $quote = Quote::factory()->create([
            'order_id' => $order->id,
            'status' => QuoteStatus::Pending,
            'expires_at' => now()->subHour(),
        ]);

        app(QuoteService::class)->expireStaleQuotes();

        $this->assertSame(QuoteStatus::Expired, $quote->refresh()->status);
        $this->assertSame(OrderStatus::InspectionOnly, $order->refresh()->status);
    }

    public function test_leaves_a_fresh_quote_untouched(): void
    {
        $quote = Quote::factory()->create([
            'status' => QuoteStatus::Pending,
            'expires_at' => now()->addHours(5),
        ]);

        app(QuoteService::class)->expireStaleQuotes();

        $this->assertSame(QuoteStatus::Pending, $quote->refresh()->status);
    }

    public function test_the_scheduled_command_runs(): void
    {
        $this->artisan('quotes:expire')->assertSuccessful();
    }
}
