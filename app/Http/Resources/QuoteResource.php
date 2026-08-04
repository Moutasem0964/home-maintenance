<?php

namespace App\Http\Resources;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'labor_cost' => $this->labor_cost,
            'warranty_days' => $this->warranty_days,
            'justification' => $this->justification,
            'expires_at' => $this->expires_at,
            'parts' => QuotePartResource::collection($this->whenLoaded('parts')),
            'total' => $this->whenLoaded('parts', fn () => bcadd((string) $this->labor_cost, (string) $this->parts->sum('price'), 2)),
        ];
    }
}
