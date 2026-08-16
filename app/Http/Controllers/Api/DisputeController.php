<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\NotificationCategory;
use App\Enums\OrderPhotoKind;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dispute\RaiseDisputeRequest;
use App\Http\Requests\Dispute\ResolveDisputeRequest;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Services\DisputeService;
use App\Services\NotificationService;
use App\Services\OrderPhotoService;
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
            ->with('photos')
            ->latest()
            ->get();

        return DisputeResource::collection($disputes);
    }

    /** Client raises a dispute on their own completed order during the dispute window. */
    public function store(RaiseDisputeRequest $request, int $order, DisputeService $disputeService, NotificationService $notificationService, OrderPhotoService $photoService): DisputeResource
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

        // Attach the client's evidence photos (if any) to the order under the dispute kind.
        if ($request->hasFile('photos')) {
            $photoService->store($orderModel, $request->file('photos'), OrderPhotoKind::Dispute, $user->id);
        }

        if ($orderModel->technician_id !== null) {
            $notificationService->notify(
                $orderModel->technician->user,
                NotificationCategory::Orders,
                'نزاع على طلبك',
                'فتح العميل نزاعاً على أحد طلباتك — قيد مراجعة الإدارة.',
                $orderModel,
            );
        }

        return new DisputeResource($dispute->load('photos'));
    }

    /** Admin resolves the dispute and routes the escrow money accordingly. */
    public function resolve(ResolveDisputeRequest $request, int $dispute, DisputeService $disputeService, NotificationService $notificationService): DisputeResource
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

        /** @var Order $order */
        $order = $resolved->order()->firstOrFail();
        $notificationService->notify($order->client, NotificationCategory::Orders, 'تم حسم النزاع', 'اتخذ المشرف قراراً بشأن النزاع على طلبك.', $order);
        if ($order->technician_id !== null) {
            $notificationService->notify($order->technician->user, NotificationCategory::Orders, 'تم حسم النزاع', 'اتخذ المشرف قراراً بشأن النزاع على أحد طلباتك.', $order);
        }

        return new DisputeResource($resolved);
    }
}
