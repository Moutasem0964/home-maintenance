<?php

namespace App\Http\Controllers\Api;

use App\Enums\DispatchOfferStatus;
use App\Enums\NotificationCategory;
use App\Exceptions\OfferUnavailableException;
use App\Http\Controllers\Concerns\ResolvesTechnician;
use App\Http\Controllers\Controller;
use App\Http\Requests\Technician\DeclineOfferRequest;
use App\Http\Resources\DispatchOfferResource;
use App\Http\Resources\OrderResource;
use App\Models\DispatchOffer;
use App\Services\AssignmentService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TechnicianOfferController extends Controller
{
    use ResolvesTechnician;

    public function index(Request $request): AnonymousResourceCollection
    {
        $technician = $this->technicianFor($request);

        $offers = $technician->dispatchOffers()
            ->where('status', DispatchOfferStatus::Offered)
            ->where('expires_at', '>', now())
            ->with('order')
            ->latest('offered_at')
            ->get();

        return DispatchOfferResource::collection($offers);
    }

    public function accept(Request $request, int $offer, AssignmentService $assignmentService, NotificationService $notificationService): OrderResource
    {
        $technician = $this->technicianFor($request);

        /** @var DispatchOffer $dispatchOffer */
        $dispatchOffer = $technician->dispatchOffers()->findOrFail($offer);

        try {
            $order = $assignmentService->accept($dispatchOffer);

            $notificationService->notify(
                $order->client,
                NotificationCategory::Orders,
                'تم قبول طلبك',
                'الفني في طريقه إليك الآن.',
                $order,
            );

            return new OrderResource($order);
        } catch (OfferUnavailableException $e) {
            abort(409, $e->getMessage());
        }
    }

    public function decline(DeclineOfferRequest $request, int $offer, AssignmentService $assignmentService): JsonResponse
    {
        $technician = $this->technicianFor($request);

        /** @var DispatchOffer $dispatchOffer */
        $dispatchOffer = $technician->dispatchOffers()->findOrFail($offer);

        try {
            $assignmentService->decline($dispatchOffer, $request->validated('reason'));

            return response()->json(['message' => 'Offer declined.']);
        } catch (OfferUnavailableException $e) {
            abort(409, $e->getMessage());
        }
    }
}
