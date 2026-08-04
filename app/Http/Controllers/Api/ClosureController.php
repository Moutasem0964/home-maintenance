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
    /** Assigned technician signals the work is done; the server mints a code for the client. */
    public function requestCode(Request $request, int $order, ClosureService $closureService): JsonResponse
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);
        abort_unless($orderModel->status === OrderStatus::InProgress, 409, 'The order is not in progress.');

        $closureService->generate($orderModel);

        return response()->json([
            'message' => 'A closure code is now available to the client.',
            'expires_at' => $orderModel->fresh()?->closure_expires_at,
        ]);
    }

    /** Client reads their active closure code to give to the technician. */
    public function code(Request $request, int $order, ClosureService $closureService): JsonResponse
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');

        $code = $closureService->activeCodeFor($orderModel);
        abort_if($code === null, 404, 'No active closure code — ask the technician to request closure.');

        return response()->json([
            'code' => $code,
            'expires_at' => $orderModel->fresh()?->closure_expires_at,
        ]);
    }

    /** Assigned technician submits the code (obtained from the client) to complete the job. */
    public function verify(VerifyClosureRequest $request, int $order, ClosureService $closureService): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        try {
            return new OrderResource($closureService->verify($orderModel, (string) $request->validated('code')));
        } catch (ClosureCodeException $e) {
            abort(422, $e->getMessage());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    private function assertAssignedTechnician(Request $request, Order $order): void
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Technician|null $technician */
        $technician = $user->technician()->first();
        abort_if($technician === null || $order->technician_id !== $technician->id, 403, 'This is not your order.');
    }
}
