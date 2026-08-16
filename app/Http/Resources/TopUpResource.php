<?php

namespace App\Http\Resources;

use App\Models\TopUp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TopUp */
class TopUpResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'reference' => $this->gateway_reference,
            'status' => $this->status,
            'has_receipt' => $this->receipt_url !== null,
            'created_at' => $this->created_at,
        ];
    }
}
