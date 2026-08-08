<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TechnicianStatus;
use App\Models\AppSetting;
use App\Models\Technician;

class ProbationService
{
    /**
     * Promote a probation technician to active once they've completed enough orders at a
     * high enough average rating (both admin-tunable settings). No-op for anyone not on
     * probation. Called after each order completion and each new review.
     */
    public function evaluate(Technician $technician): void
    {
        if ($technician->status !== TechnicianStatus::Probation) {
            return;
        }

        if ($this->completedOrders($technician) < (int) AppSetting::get('promotion_min_orders', 5)) {
            return;
        }

        $minRating = (float) AppSetting::get('promotion_min_rating', 4.0);
        if ($technician->rating_avg === null || (float) $technician->rating_avg < $minRating) {
            return;
        }

        $technician->update([
            'status' => TechnicianStatus::Active,
            'daily_order_limit' => null,
        ]);
    }

    /**
     * Progress toward promotion, for the technician app.
     *
     * @return array<string, mixed>
     */
    public function progress(Technician $technician): array
    {
        $minOrders = (int) AppSetting::get('promotion_min_orders', 5);
        $minRating = (float) AppSetting::get('promotion_min_rating', 4.0);
        $completed = $this->completedOrders($technician);

        return [
            'status' => $technician->status,
            'completed_orders' => $completed,
            'min_orders' => $minOrders,
            'orders_remaining' => max(0, $minOrders - $completed),
            'rating_avg' => $technician->rating_avg,
            'min_rating' => $minRating,
            'meets_rating' => $technician->rating_avg !== null && (float) $technician->rating_avg >= $minRating,
        ];
    }

    private function completedOrders(Technician $technician): int
    {
        return $technician->orders()
            ->whereIn('status', [OrderStatus::Completed, OrderStatus::Resolved])
            ->count();
    }
}
