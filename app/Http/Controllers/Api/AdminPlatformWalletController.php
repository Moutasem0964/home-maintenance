<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\PlatformTopUpRequest;
use App\Http\Resources\WalletResource;
use App\Models\User;
use App\Services\PlatformService;
use App\Services\WalletService;
use App\Services\WarrantyPayoutService;
use Illuminate\Support\Str;

class AdminPlatformWalletController extends Controller
{
    /**
     * Admin tops up the platform guarantee fund. Immediately retries any warranty substitute
     * payouts that were waiting on funds, so a top-up settles stuck obligations without waiting
     * for the sweep.
     */
    public function topUp(
        PlatformTopUpRequest $request,
        WalletService $walletService,
        PlatformService $platformService,
        WarrantyPayoutService $warrantyPayoutService,
    ): WalletResource {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');

        $wallet = $walletService->topUp(
            $platformService->account(),
            (string) $request->validated('amount'),
            'admin-topup:'.Str::uuid(),
        );

        $warrantyPayoutService->retryPending();

        return new WalletResource($wallet->refresh());
    }
}
