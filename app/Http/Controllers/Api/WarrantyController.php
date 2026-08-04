<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warranty\WarrantyClaimRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\WarrantyService;

class WarrantyController extends Controller
{
    /** Client claims the warranty on their completed order, spawning a same-tech visit. */
    public function claim(WarrantyClaimRequest $request, int $order, WarrantyService $warrantyService): OrderResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');

        $description = $request->validated('description');

        try {
            $warranty = $warrantyService->claim(
                $orderModel,
                $user,
                $description !== null ? (string) $description : null,
            );
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new OrderResource($warranty);
    }
}
