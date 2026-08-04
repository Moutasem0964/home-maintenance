<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Review\StoreReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Models\User;
use App\Services\ReviewService;

class ReviewController extends Controller
{
    /** Client leaves the single review for their completed order. */
    public function store(StoreReviewRequest $request, int $order, ReviewService $reviewService): ReviewResource
    {
        $orderModel = Order::findOrFail($order);

        /** @var User $user */
        $user = $request->user();
        abort_unless($orderModel->client_id === $user->id, 403, 'This is not your order.');

        $comment = $request->validated('comment');

        try {
            $review = $reviewService->submit(
                $orderModel,
                $user,
                (int) $request->validated('cleanliness'),
                (int) $request->validated('quality'),
                (int) $request->validated('price_rating'),
                $comment !== null ? (string) $comment : null,
            );
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        }

        return new ReviewResource($review);
    }
}
