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
            'service_category_name' => $this->serviceCategory?->name,
            'address' => $this->whenLoaded('address', fn () => $this->address ? new AddressResource($this->address) : null),
            'lat' => $this->lat,
            'lng' => $this->lng,
            'description' => $this->description,
            'scheduled_at' => $this->scheduled_at,
            'arrived_at' => $this->arrived_at,
            'parts_waiting_until' => $this->parts_waiting_until,
            'inspection_fee' => $this->inspection_fee,
            'commission_rate' => $this->commission_rate,
            'photos' => OrderPhotoResource::collection($this->whenLoaded('photos')),
            'created_at' => $this->created_at,
        ];
    }
}
