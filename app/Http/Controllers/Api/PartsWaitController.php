<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTechnician;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\WaitForPartsRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\PartsWaitService;
use Illuminate\Http\Request;

class PartsWaitController extends Controller
{
    use ResolvesTechnician;

    /** Assigned technician pauses the in-progress job to source a part. */
    public function wait(WaitForPartsRequest $request, int $order, PartsWaitService $service): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        try {
            return new OrderResource($service->startWaiting($orderModel, $request->validated('note')));
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    /** Assigned technician returns with the part and resumes the job. */
    public function resume(Request $request, int $order, PartsWaitService $service): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        try {
            return new OrderResource($service->resume($orderModel));
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
