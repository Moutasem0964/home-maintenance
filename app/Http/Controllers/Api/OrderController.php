<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPhotoKind;
use App\Enums\UserRole;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderIndexRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderPhotoService;
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

    public function store(StoreOrderRequest $request, OrderService $orderService, OrderPhotoService $photoService): JsonResponse
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

        // Attach the client's flaw photos once (guard keeps idempotent retries clean).
        if ($request->hasFile('photos') && $order->photos()->doesntExist()) {
            $photoService->store($order, $request->file('photos'), OrderPhotoKind::Flaw, $user->id);
        }

        return (new OrderResource($order->loadMissing('address', 'photos')))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $order): OrderResource
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Order $model */
        $model = Order::findOrFail($order);

        // Role-aware access: the client who placed it, the technician assigned to it, or an admin.
        // 404 (not 403) for anyone else so an outsider can't even confirm the order exists.
        $isClient = $model->client_id === $user->id;
        $isTechnician = $model->technician_id !== null && $model->technician_id === $user->technician()->value('id');
        $isAdmin = $user->role === UserRole::Admin;

        abort_unless($isClient || $isTechnician || $isAdmin, 404);

        return new OrderResource($model);
    }
}
