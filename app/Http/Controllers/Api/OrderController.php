<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return OrderResource::collection($user->orders()->latest()->get());
    }

    public function store(StoreOrderRequest $request, OrderService $orderService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $order = $orderService->create($user, $request->validated());
        } catch (InsufficientBalanceException) {
            throw ValidationException::withMessages([
                'inspection_fee' => 'Insufficient wallet balance to hold the inspection fee.',
            ]);
        }

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order): OrderResource
    {
        /** @var User $user */
        $user = $request->user();

        return new OrderResource($user->orders()->findOrFail($order));
    }
}
