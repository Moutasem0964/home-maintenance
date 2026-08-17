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
            // Same field name and shape as before (a loadable URL); now points at the
            // authed streaming route for the privately-stored photo, like order photos.
            'image_url' => url("/api/quote-parts/{$this->id}/image"),
        ];
    }
}
