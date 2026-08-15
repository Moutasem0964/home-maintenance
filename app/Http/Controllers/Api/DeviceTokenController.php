<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceToken\StoreDeviceTokenRequest;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /** Register (or move) an FCM device token for the authenticated user. */
    public function store(StoreDeviceTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $token = DeviceToken::updateOrCreate(
            ['token' => $request->validated('token')],
            ['user_id' => $user->id, 'platform' => $request->validated('platform')],
        );

        return response()->json(['data' => ['id' => $token->id]], 201);
    }

    /** Remove a device token (called on logout). */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->deviceTokens()->where('token', (string) $request->input('token'))->delete();

        return response()->json(['message' => 'Device token removed.']);
    }
}
