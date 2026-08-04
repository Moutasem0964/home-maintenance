<?php

namespace App\Http\Resources;

use App\Models\QuotePart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuotePart */
class QuotePartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'classification' => $this->classification,
            'image_url' => $this->image_url,
        ];
    }
}
