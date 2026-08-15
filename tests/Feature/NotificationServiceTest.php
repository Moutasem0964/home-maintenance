<?php

namespace Tests\Feature;

use App\Contracts\PushSender;
use App\Enums\NotificationCategory;
use App\Enums\OrderStatus;
use App\Models\DeviceToken;
use App\Models\Order;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    /** A PushSender spy that records what it was asked to send. */
    private function spySender(): PushSender
    {
        return new class implements PushSender
        {
            /** @var array<int, array{tokens: array<int, string>, title: string, data: array<string, string>}> */
            public array $sent = [];

            public function send(array $tokens, string $title, string $body, array $data = []): void
            {
                $this->sent[] = ['tokens' => $tokens, 'title' => $title, 'data' => $data];
            }
        };
    }

    public function test_notify_records_a_bell_notification_and_pushes_to_every_device(): void
    {
        $spy = $this->spySender();
        $this->app->instance(PushSender::class, $spy);

        $user = User::factory()->create();
        DeviceToken::create(['user_id' => $user->id, 'token' => 't1', 'platform' => 'android']);
        DeviceToken::create(['user_id' => $user->id, 'token' => 't2', 'platform' => 'ios']);

        app(NotificationService::class)->notify($user, NotificationCategory::Orders, 'عنوان', 'نص');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'title' => 'عنوان', 'category' => 'orders',
        ]);
        $this->assertCount(1, $spy->sent);
        $this->assertEqualsCanonicalizing(['t1', 't2'], $spy->sent[0]['tokens']);
    }

    public function test_notify_still_records_the_bell_when_the_user_has_no_devices(): void
    {
        $spy = $this->spySender();
        $this->app->instance(PushSender::class, $spy);

        $user = User::factory()->create();
        app(NotificationService::class)->notify($user, NotificationCategory::Orders, 't', 'b');

        $this->assertDatabaseHas('notifications', ['user_id' => $user->id]);
        $this->assertCount(0, $spy->sent); // no devices → nothing pushed
    }

    public function test_a_domain_subject_adds_deep_link_data(): void
    {
        $spy = $this->spySender();
        $this->app->instance(PushSender::class, $spy);

        $user = User::factory()->create();
        DeviceToken::create(['user_id' => $user->id, 'token' => 't', 'platform' => 'android']);
        $order = Order::factory()->create();

        app(NotificationService::class)->notify($user, NotificationCategory::Orders, 't', 'b', $order);

        $this->assertSame(['type' => 'Order', 'id' => (string) $order->id], $spy->sent[0]['data']);
    }

    public function test_accepting_an_offer_notifies_the_client(): void
    {
        $category = ServiceCategory::factory()->create();
        $order = Order::factory()->create([
            'service_category_id' => $category->id, 'lat' => 33.5, 'lng' => 36.3, 'status' => OrderStatus::Pending,
        ]);
        $tech = Technician::factory()->available()->create(['current_lat' => 33.5, 'current_lng' => 36.3]);
        $tech->services()->attach($category->id);
        $offer = app(AssignmentService::class)->offerToNext($order);

        $this->actingAs($tech->user()->firstOrFail(), 'sanctum')
            ->postJson("/api/technician/offers/{$offer->id}/accept")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $order->client_id,
            'category' => 'orders',
            'notifiable_type' => Order::class,
            'notifiable_id' => $order->id,
        ]);
    }
}
