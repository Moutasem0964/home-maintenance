<?php

namespace App\Http\Resources;

use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Technician */
class TechnicianResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'is_available' => $this->is_available,
            'current_lat' => $this->current_lat,
            'current_lng' => $this->current_lng,
            'rating_avg' => $this->rating_avg,
            // Category ids this technician serves; never the encrypted KYC docs.
            'service_category_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')),
        ];
    }
}
