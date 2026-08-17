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
            // Sham Cash payout account — confirm it's set without echoing the full number back.
            'sham_cash_name' => $this->sham_cash_name,
            'sham_cash_last4' => $this->sham_cash_number !== null ? substr($this->sham_cash_number, -4) : null,
            'has_sham_cash_account' => $this->sham_cash_number !== null,
            // Category ids this technician serves; never the encrypted KYC docs.
            'service_category_ids' => $this->whenLoaded('services', fn () => $this->services->pluck('id')),
        ];
    }
}
