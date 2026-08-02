<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\TopUpRequest;
use App\Http\Resources\WalletResource;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function show(Request $request): WalletResource
    {
        /** @var User $user */
        $user = $request->user();

        return new WalletResource($user->wallet()->firstOrFail());
    }

    public function topUp(TopUpRequest $request, WalletService $walletService): WalletResource
    {
        /** @var User $user */
        $user = $request->user();

        $wallet = $walletService->topUp(
            $user,
            (string) $request->validated('amount'),
            (string) $request->validated('gateway_reference'),
        );

        return new WalletResource($wallet);
    }
}
