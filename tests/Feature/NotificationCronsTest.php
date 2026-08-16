<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Models\Wallet;
use App\Services\EscrowService;
use App\Services\WalletService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCronsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    public function test_releasing_funds_notifies_the_technician(): void
    {
        $client = User::factory()->create();
        Wallet::create(['user_id' => $client->id]);
        app(WalletService::class)->topUp($client, '500.00', 'seed-'.uniqid());

        $tech = Technician::factory()->active()->create();
        Wallet::create(['user_id' => $tech->user_id]);

        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
            'commission_rate' => '0.1000',
            'inspection_fee' => '50.00',
        ]);
        app(EscrowService::class)->holdFunds($order, '50.00', PaymentType::Inspection, "insp:{$order->id}", "op:{$order->id}");
        $order->update(['status' => OrderStatus::Completed, 'dispute_deadline_at' => now()->subHour()]);

        app(EscrowService::class)->releaseFunds($order, "release:{$order->id}");

        $this->assertDatabaseHas('notifications', [
            'user_id' => $tech->user_id,
            'category' => 'financial',
            'notifiable_type' => Order::class,
            'notifiable_id' => $order->id,
        ]);
    }
}
