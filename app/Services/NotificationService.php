<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for user notifications: records the in-app bell notification
 * AND queues a push to the user's registered devices. The push is dispatched as a
 * job (async in production, inline on the sync queue in tests) so it is safe to call
 * from anywhere — controllers, services, or scheduled commands.
 */
class NotificationService
{
    /**
     * @param  Model|null  $about  the domain subject (order / dispute / …) for deep-linking
     */
    public function notify(User $user, NotificationCategory $category, string $title, string $body, ?Model $about = null): void
    {
        AppNotification::create([
            'user_id' => $user->id,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'notifiable_type' => $about !== null ? $about::class : null,
            'notifiable_id' => $about?->getKey(),
        ]);

        /** @var array<int, string> $tokens */
        $tokens = $user->deviceTokens()->pluck('token')->all();
        if ($tokens === []) {
            return;
        }

        $data = [];
        if ($about !== null) {
            $data['type'] = class_basename($about);
            $data['id'] = (string) $about->getKey();
        }

        SendPushNotification::dispatch($tokens, $title, $body, $data);
    }
}
