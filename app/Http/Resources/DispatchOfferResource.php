<?php

namespace App\Http\Resources;

use App\Models\DispatchOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DispatchOffer */
class DispatchOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'offered_at' => $this->offered_at,
            'expires_at' => $this->expires_at,
            'order' => new OrderResource($this->whenLoaded('order')),
        ];
    }
}
