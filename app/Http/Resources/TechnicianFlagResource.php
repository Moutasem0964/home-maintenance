<?php

namespace App\Http\Resources;

use App\Models\TechnicianFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TechnicianFlag */
class TechnicianFlagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'technician_id' => $this->technician_id,
            'order_id' => $this->order_id,
            'reason' => $this->reason,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'note' => $this->note,
            'reviewed_at' => $this->reviewed_at,
            'created_at' => $this->created_at,
        ];
    }
}
