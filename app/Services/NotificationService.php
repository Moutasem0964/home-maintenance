<?php

namespace App\Services;

use App\Contracts\PushSender;
use App\Enums\NotificationCategory;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for user notifications: records the in-app bell notification
 * AND pushes it to the user's registered devices. Call it AFTER the domain operation
 * has committed (typically from the controller), never inside a DB transaction.
 */
class NotificationService
{
    public function __construct(private readonly PushSender $pushSender) {}

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

        $this->pushSender->send($tokens, $title, $body, $data);
    }
}
