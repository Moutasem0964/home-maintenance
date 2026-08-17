<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuotePart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuotePartController extends Controller
{
    /** Stream a private part photo to an authorized viewer: the order's client, its tech, or an admin. */
    public function image(Request $request, QuotePart $quotePart): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Quote $quote */
        $quote = $quotePart->quote()->firstOrFail();
        /** @var Order $order */
        $order = $quote->order()->firstOrFail();

        $isClient = $order->client_id === $user->id;
        $isTech = $order->technician_id !== null && $order->technician_id === $user->technician()->value('id');
        $isAdmin = $user->role === UserRole::Admin;

        abort_unless($isClient || $isTech || $isAdmin, 403, 'You cannot view this photo.');
        abort_unless(Storage::disk('local')->exists($quotePart->image_url), 404);

        return Storage::disk('local')->response($quotePart->image_url);
    }
}
