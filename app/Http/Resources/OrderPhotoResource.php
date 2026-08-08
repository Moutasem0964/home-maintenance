<?php

namespace App\Http\Resources;

use App\Models\OrderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderPhoto */
class OrderPhotoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            // Authorized serve endpoint (the file itself is private, not a public URL).
            'url' => url("/api/order-photos/{$this->id}"),
        ];
    }
}
