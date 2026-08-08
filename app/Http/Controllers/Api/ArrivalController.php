<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ArrivalOutOfRangeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\ArriveRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Technician;
use App\Models\User;
use App\Services\ArrivalService;

class ArrivalController extends Controller
{
    /** Assigned technician marks on-site arrival (geofenced); stamps arrived_at. */
    public function store(ArriveRequest $request, int $order, ArrivalService $arrivalService): OrderResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        /** @var Technician|null $technician */
        $technician = $user->technician()->first();
        abort_if($technician === null || $orderModel->technician_id !== $technician->id, 403, 'This is not your order.');

        try {
            $updated = $arrivalService->markArrived(
                $orderModel,
                (float) $request->validated('lat'),
                (float) $request->validated('lng'),
            );

            return new OrderResource($updated);
        } catch (ArrivalOutOfRangeException $e) {
            abort(422, $e->getMessage());
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }
    }
}
