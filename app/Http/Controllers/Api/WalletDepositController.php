<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreDepositRequest;
use App\Http\Resources\TopUpResource;
use App\Models\TopUp;
use App\Models\User;
use App\Services\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletDepositController extends Controller
{
    /** Client submits proof of a cash/bank transfer; an admin reviews it before crediting. */
    public function store(StoreDepositRequest $request, DepositService $depositService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $topUp = $depositService->request(
            $user,
            (string) $request->validated('amount'),
            (string) $request->validated('reference'),
            $request->file('receipt'),
        );

        return (new TopUpResource($topUp))->response()->setStatusCode(201);
    }

    /** The caller's own top-up requests (manual and instant), newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $walletId = $user->wallet()->value('id');

        return TopUpResource::collection(
            TopUp::where('wallet_id', $walletId)->latest()->get(),
        );
    }
}
