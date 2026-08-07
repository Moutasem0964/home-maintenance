<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderIndexRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(OrderIndexRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        // Role-aware: a technician sees the orders assigned to them; a client sees
        // the orders they placed. (0 is an unmatchable id so a tech with no profile
        // gets an empty list rather than every unassigned order.)
        $query = $user->isTechnician()
            ? Order::query()->where('technician_id', $user->technician()->value('id') ?? 0)
            : $user->orders()->getQuery();

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        return OrderResource::collection($query->latest()->get());
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

        return (new OrderResource($order->loadMissing('address')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order): OrderResource
    {
        /** @var User $user */
        $user = $request->user();

        return new OrderResource($user->orders()->findOrFail($order));
    }
}
