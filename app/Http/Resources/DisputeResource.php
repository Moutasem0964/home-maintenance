<?php

namespace App\Http\Resources;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// OrderPhotoResource is in the same namespace (App\Http\Resources).

/** @mixin Dispute */
class DisputeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'reason' => $this->reason,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'description' => $this->description,
            'photos' => OrderPhotoResource::collection($this->whenLoaded('photos')),
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
        ];
    }
}
