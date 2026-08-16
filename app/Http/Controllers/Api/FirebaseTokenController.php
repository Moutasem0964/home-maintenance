<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CustomTokenMinter;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirebaseTokenController extends Controller
{
    /**
     * Mint a short-lived Firebase custom token for the signed-in user. The client calls
     * signInWithCustomToken() with it to read/write the Realtime Database live-location node,
     * where the RTDB rules match on this uid (the app user id) and its role/admin claims.
     */
    public function issue(Request $request, CustomTokenMinter $minter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $claims = ['role' => $user->role->value];
        if ($user->role === UserRole::Admin) {
            $claims['admin'] = true;
        }

        return response()->json(['token' => $minter->mint((string) $user->id, $claims)]);
    }
}
