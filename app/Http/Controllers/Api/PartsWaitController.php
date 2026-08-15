<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Concerns\ResolvesTechnician;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\WaitForPartsRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\NotificationService;
use App\Services\PartsWaitService;
use Illuminate\Http\Request;

class PartsWaitController extends Controller
{
    use ResolvesTechnician;

    /** Assigned technician pauses the in-progress job to source a part. */
    public function wait(WaitForPartsRequest $request, int $order, PartsWaitService $service, NotificationService $notificationService): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        try {
            $updated = $service->startWaiting($orderModel, $request->validated('note'));

            $notificationService->notify(
                $updated->client,
                NotificationCategory::Orders,
                'بانتظار قطعة غيار',
                'أوقف الفني العمل مؤقتاً لتأمين قطعة غيار.',
                $updated,
            );

            return new OrderResource($updated);
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    /** Assigned technician returns with the part and resumes the job. */
    public function resume(Request $request, int $order, PartsWaitService $service, NotificationService $notificationService): OrderResource
    {
        $orderModel = Order::findOrFail($order);
        $this->assertAssignedTechnician($request, $orderModel);

        try {
            $updated = $service->resume($orderModel);

            $notificationService->notify(
                $updated->client,
                NotificationCategory::Orders,
                'استئناف العمل',
                'عاد الفني لاستكمال طلبك.',
                $updated,
            );

            return new OrderResource($updated);
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
