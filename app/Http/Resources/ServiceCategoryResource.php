<?php

namespace App\Http\Resources;

use App\Models\ServiceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceCategory */
class ServiceCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon_url' => $this->icon_url,
            'guide_price' => $this->guide_price,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
