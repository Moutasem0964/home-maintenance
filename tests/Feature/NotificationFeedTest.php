<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private function notify(User $user, ?string $readAt = null): AppNotification
    {
        return AppNotification::create([
            'user_id' => $user->id,
            'category' => NotificationCategory::Orders,
            'title' => 'عنوان',
            'body' => 'نص',
            'read_at' => $readAt,
        ]);
    }

    public function test_the_feed_requires_authentication(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_it_lists_only_the_callers_notifications_newest_first(): void
    {
        $user = User::factory()->create();
        $older = $this->notify($user);
        $newer = $this->notify($user);
        $this->notify(User::factory()->create()); // someone else's

        $ids = $this->actingAs($user, 'sanctum')->getJson('/api/notifications')
            ->assertOk()->json('data.*.id');

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_it_can_filter_to_unread(): void
    {
        $user = User::factory()->create();
        $unread = $this->notify($user);
        $this->notify($user, readAt: now()->toDateTimeString());

        $ids = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?status=unread')
            ->assertOk()->json('data.*.id');

        $this->assertSame([$unread->id], $ids);
    }

    public function test_unread_count(): void
    {
        $user = $this->userWith(unread: 2, read: 1);

        $this->actingAs($user, 'sanctum')->getJson('/api/notifications/unread-count')
            ->assertOk()->assertJsonPath('count', 2);
    }

    public function test_marking_one_read(): void
    {
        $user = User::factory()->create();
        $n = $this->notify($user);

        $this->actingAs($user, 'sanctum')->postJson("/api/notifications/{$n->id}/read")
            ->assertOk()->assertJsonPath('data.is_read', true);

        $this->assertNotNull($n->refresh()->read_at);
    }

    public function test_cannot_mark_someone_elses_notification(): void
    {
        $n = $this->notify(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/notifications/{$n->id}/read")->assertForbidden();

        $this->assertNull($n->refresh()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = $this->userWith(unread: 3, read: 0);

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/read-all')
            ->assertOk()->assertJsonPath('updated', 3);

        $this->assertSame(0, AppNotification::where('user_id', $user->id)->whereNull('read_at')->count());
    }

    private function userWith(int $unread, int $read): User
    {
        $user = User::factory()->create();
        for ($i = 0; $i < $unread; $i++) {
            $this->notify($user);
        }
        for ($i = 0; $i < $read; $i++) {
            $this->notify($user, readAt: now()->toDateTimeString());
        }

        return $user;
    }
}
