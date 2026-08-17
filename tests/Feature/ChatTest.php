<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array{0: Order, 1: User, 2: User} an in-progress order with its client + tech user. */
    private function chattableOrder(): array
    {
        $client = User::factory()->create();
        $tech = Technician::factory()->active()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id,
            'technician_id' => $tech->id,
            'status' => OrderStatus::InProgress,
        ]);

        return [$order, $client, $tech->user];
    }

    public function test_a_participant_can_send_a_message_and_the_other_party_is_notified(): void
    {
        [$order, $client, $techUser] = $this->chattableOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'مرحبا'])
            ->assertCreated()
            ->assertJsonPath('data.text', 'مرحبا')
            ->assertJsonPath('data.mine', true);

        $this->assertDatabaseHas('messages', ['sender_id' => $client->id, 'message_text' => 'مرحبا']);
        $this->assertDatabaseHas('notifications', ['user_id' => $techUser->id, 'title' => 'رسالة جديدة']);
    }

    public function test_an_image_message(): void
    {
        [$order, $client] = $this->chattableOrder();

        $this->actingAs($client, 'sanctum')->post("/api/orders/{$order->id}/messages", [
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_image', true);
    }

    public function test_a_message_must_carry_text_or_an_image(): void
    {
        [$order, $client] = $this->chattableOrder();

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/messages", [])
            ->assertStatus(422)->assertJsonValidationErrors(['message_text', 'image']);
    }

    public function test_a_stranger_cannot_send_or_read(): void
    {
        [$order] = $this->chattableOrder();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'x'])->assertForbidden();
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/orders/{$order->id}/messages")->assertForbidden();
    }

    public function test_cannot_send_before_a_technician_is_assigned(): void
    {
        $client = User::factory()->create();
        $order = Order::factory()->create([
            'client_id' => $client->id, 'technician_id' => null, 'status' => OrderStatus::Pending,
        ]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'x'])->assertStatus(409);
    }

    public function test_a_finished_order_locks_the_conversation_read_only(): void
    {
        [$order, $client] = $this->chattableOrder();
        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'first'])->assertCreated();

        $order->update(['status' => OrderStatus::Completed]);

        $this->actingAs($client, 'sanctum')
            ->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'too late'])->assertStatus(409);

        $this->assertDatabaseHas('conversations', ['order_id' => $order->id, 'status' => 'read_only']);
        // History is still readable after locking.
        $this->actingAs($client, 'sanctum')->getJson("/api/orders/{$order->id}/messages")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_thread_is_readable_by_an_admin_newest_first(): void
    {
        [$order, $client, $techUser] = $this->chattableOrder();
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'one'])->assertCreated();
        $this->actingAs($techUser, 'sanctum')->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'two'])->assertCreated();

        $texts = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson("/api/orders/{$order->id}/messages")->assertOk()->json('data.*.text');

        $this->assertSame(['two', 'one'], $texts);
    }

    public function test_marking_the_other_partys_messages_read(): void
    {
        [$order, $client, $techUser] = $this->chattableOrder();
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'from client'])->assertCreated();
        $this->actingAs($techUser, 'sanctum')->postJson("/api/orders/{$order->id}/messages", ['message_text' => 'from tech'])->assertCreated();

        // The client reads → only the tech's message is marked.
        $this->actingAs($client, 'sanctum')->postJson("/api/orders/{$order->id}/messages/read")
            ->assertOk()->assertJsonPath('read', 1);

        $this->assertDatabaseMissing('messages', ['sender_id' => $techUser->id, 'read_at' => null]); // tech's now read
        $this->assertDatabaseHas('messages', ['message_text' => 'from client', 'read_at' => null]);   // client's own stays unread
    }
}
