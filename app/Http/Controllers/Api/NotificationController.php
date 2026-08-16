<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /** The signed-in user's bell notifications, newest first. Pass ?status=unread to filter. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = AppNotification::where('user_id', $user->id)->latest('id');

        if ($request->query('status') === 'unread') {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->paginate(20));
    }

    /** Unread count for the bell badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'count' => AppNotification::where('user_id', $user->id)->whereNull('read_at')->count(),
        ]);
    }

    /** Mark a single notification read (idempotent). */
    public function markRead(Request $request, AppNotification $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($notification->user_id === $user->id, 403, 'This is not your notification.');

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return new NotificationResource($notification);
    }

    /** Mark every unread notification read. */
    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = AppNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }
}
