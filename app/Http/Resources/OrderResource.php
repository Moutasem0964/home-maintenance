<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'type' => $this->type,
            'kind' => $this->kind,
            'service_category_id' => $this->service_category_id,
            'address_id' => $this->address_id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'description' => $this->description,
            'scheduled_at' => $this->scheduled_at,
            'inspection_fee' => $this->inspection_fee,
            'commission_rate' => $this->commission_rate,
            'created_at' => $this->created_at,
        ];
    }
}
