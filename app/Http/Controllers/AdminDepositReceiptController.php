<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\TopUp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a manual top-up's receipt to a logged-in admin for the Filament panel. The API has
 * its own sanctum-guarded receipt route; the panel is web-session authed, so it needs this one.
 */
class AdminDepositReceiptController extends Controller
{
    public function __invoke(Request $request, TopUp $topUp): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->role === UserRole::Admin, 403);
        abort_if($topUp->receipt_url === null, 404, 'No receipt on this top-up.');
        abort_unless(Storage::disk('local')->exists($topUp->receipt_url), 404);

        return Storage::disk('local')->response($topUp->receipt_url);
    }
}
