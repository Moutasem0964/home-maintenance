<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Exceptions\ClosureCodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\VerifyClosureRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Services\ClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClosureController extends Controller
{
    /** Client generates the closure code and reads it out to the technician. */
    public function generate(Request $request, int $order, ClosureService $closureService): JsonResponse
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');
        abort_unless($orderModel->status === OrderStatus::InProgress, 409, 'The order is not in progress.');

        $code = $closureService->generate($orderModel);

        return response()->json([
            'code' => $code,
            'expires_at' => $orderModel->fresh()?->closure_expires_at,
        ]);
    }

    /** Assigned technician submits the code to complete the job. */
    public function verify(VerifyClosureRequest $request, int $order, ClosureService $closureService): OrderResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        /** @var Technician|null $technician */
        $technician = $user->technician()->first();
        abort_if($technician === null || $orderModel->technician_id !== $technician->id, 403, 'This is not your order.');

        try {
            return new OrderResource($closureService->verify($orderModel, (string) $request->validated('code')));
        } catch (ClosureCodeException $e) {
            abort(422, $e->getMessage());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }
}
