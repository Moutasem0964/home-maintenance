<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPhoto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderPhotoController extends Controller
{
    /** Stream a private order photo to an authorized viewer: the order's client, its tech, or an admin. */
    public function show(Request $request, OrderPhoto $orderPhoto): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Order $order */
        $order = $orderPhoto->order()->firstOrFail();

        $isClient = $order->client_id === $user->id;
        $isTech = $order->technician_id !== null && $order->technician_id === $user->technician()->value('id');
        $isAdmin = $user->role === UserRole::Admin;

        abort_unless($isClient || $isTech || $isAdmin, 403, 'You cannot view this photo.');
        abort_unless(Storage::disk('local')->exists($orderPhoto->path), 404);

        return Storage::disk('local')->response($orderPhoto->path);
    }
}
