<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'technician_id' => $this->technician_id,
            'cleanliness' => $this->cleanliness,
            'quality' => $this->quality,
            'price_rating' => $this->price_rating,
            'comment' => $this->comment,
            'price_anomaly_flag' => $this->price_anomaly_flag,
            'created_at' => $this->created_at,
        ];
    }
}
