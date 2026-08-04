<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

class ReviewService
{
    /**
     * The client leaves the one-and-only review for a finished order. price_anomaly_flag
     * is derived server-side from a low price rating, so the admin board can surface
     * suspected overcharging without trusting a client-set flag. The unique (order_id)
     * index is the real single-review guarantee; the pre-check just gives a clean error.
     */
    public function submit(Order $order, User $client, int $cleanliness, int $quality, int $priceRating, ?string $comment): Review
    {
        if (! in_array($order->status, [OrderStatus::Completed, OrderStatus::Resolved], true)) {
            throw new \DomainException('You can only review a completed order.');
        }

        if ($order->technician_id === null) {
            throw new \DomainException('This order has no technician to review.');
        }

        if ($order->review()->exists()) {
            throw new \DomainException('You have already reviewed this order.');
        }

        try {
            return Review::create([
                'order_id' => $order->id,
                'client_id' => $client->id,
                'technician_id' => $order->technician_id,
                'cleanliness' => $cleanliness,
                'quality' => $quality,
                'price_rating' => $priceRating,
                'comment' => $comment,
                'price_anomaly_flag' => $priceRating <= 2,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent second review lost the unique-index race.
            throw new \DomainException('You have already reviewed this order.');
        }
    }
}
