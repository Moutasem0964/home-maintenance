<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ResolveNoShowRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\CancellationService;

class AdminNoShowController extends Controller
{
    /** Admin confirms or dismisses a reported no-show (technician or client), routing by the open claim. */
    public function resolve(ResolveNoShowRequest $request, int $order, CancellationService $cancellationService): OrderResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');

        $orderModel = Order::findOrFail($order);
        $note = $request->validated('note');

        try {
            return new OrderResource($cancellationService->resolveNoShow(
                $orderModel,
                $user,
                $request->validated('outcome') === 'confirmed',
                $note !== null ? (string) $note : null,
            ));
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }
}
