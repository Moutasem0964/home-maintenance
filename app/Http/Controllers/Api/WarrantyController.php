<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warranty\WarrantyClaimRequest;
use App\Http\Requests\Warranty\WarrantyIndexRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\WarrantyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class WarrantyController extends Controller
{
    /**
     * The client's warranty screen. filter=covered → original orders still under active
     * warranty (can be claimed); filter=claimed → the warranty visits already requested.
     */
    public function index(WarrantyIndexRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = Order::query()
            ->where('client_id', $user->id)
            ->with('serviceCategory');

        if ($request->validated('filter') === 'claimed') {
            $query->where('kind', OrderKind::Warranty);
        } else {
            $query->where('kind', '!=', OrderKind::Warranty)
                ->whereNotNull('warranty_until')
                ->where('warranty_until', '>=', now());
        }

        return OrderResource::collection($query->latest()->get());
    }

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
                Carbon::parse((string) $request->validated('scheduled_at')),
                $description !== null ? (string) $description : null,
            );
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new OrderResource($warranty->loadMissing('address'));
    }
}
