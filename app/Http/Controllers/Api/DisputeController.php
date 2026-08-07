<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\RaiseDisputeRequest;
use App\Http\Requests\Dispute\ResolveDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Services\DisputeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DisputeController extends Controller
{
    /** The authenticated client's disputes (across all their orders), newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $disputes = Dispute::query()
            ->whereHas('order', fn ($query) => $query->where('client_id', $user->id))
            ->latest()
            ->get();

        return DisputeResource::collection($disputes);
    }

    /** Client raises a dispute on their own completed order during the dispute window. */
    public function store(RaiseDisputeRequest $request, int $order, DisputeService $disputeService): DisputeResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');

        $description = $request->validated('description');

        try {
            $dispute = $disputeService->raise(
                $orderModel,
                $user,
                DisputeReason::from((string) $request->validated('reason')),
                $description !== null ? (string) $description : null,
            );
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new DisputeResource($dispute);
    }

    /** Admin resolves the dispute and routes the escrow money accordingly. */
    public function resolve(ResolveDisputeRequest $request, int $dispute, DisputeService $disputeService): DisputeResource
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403, 'Admins only.');

        $disputeModel = Dispute::findOrFail($dispute);
        $refundAmount = $request->validated('refund_amount');

        try {
            $resolved = $disputeService->resolve(
                $disputeModel,
                $user,
                DisputeResolution::from((string) $request->validated('resolution')),
                $refundAmount !== null ? (string) $refundAmount : null,
            );
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return new DisputeResource($resolved);
    }
}
