<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTechnician;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\CancellationService;
use Illuminate\Http\Request;

class CancellationController extends Controller
{
    use ResolvesTechnician;

    /** Client cancels their own order. */
    public function cancel(Request $request, int $order, CancellationService $cancellationService): OrderResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');

        return $this->run(fn () => $cancellationService->cancelByClient($orderModel));
    }

    /** Assigned technician withdraws from a job they accepted but haven't started. */
    public function withdraw(Request $request, int $order, CancellationService $cancellationService): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        return $this->run(fn () => $cancellationService->technicianWithdraw($orderModel));
    }

    /** Assigned technician reports the client was a no-show on-site. */
    public function clientNoShow(Request $request, int $order, CancellationService $cancellationService): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        return $this->run(fn () => $cancellationService->clientNoShow($orderModel));
    }

    /** Client reports the technician never arrived. */
    public function technicianNoShow(Request $request, int $order, CancellationService $cancellationService): OrderResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');

        return $this->run(fn () => $cancellationService->technicianNoShow($orderModel));
    }

    /** @param  callable(): Order  $action */
    private function run(callable $action): OrderResource
    {
        try {
            return new OrderResource($action());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    private function assertAssignedTechnician(Request $request, Order $order): void
    {
        $technician = $this->technicianFor($request);
        abort_if($order->technician_id !== $technician->id, 403, 'This is not your job.');
    }
}
