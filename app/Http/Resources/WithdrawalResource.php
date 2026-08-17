<?php

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Withdrawal */
class WithdrawalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'status' => $this->status,
            // Snapshotted Sham Cash destination — the owning technician (their own) and the
            // admin (who makes the transfer) are the only readers of this resource.
            'sham_cash_number' => $this->destination_details,
            'sham_cash_name' => $this->destination_name,
            'has_receipt' => $this->receipt_url !== null,
            'created_at' => $this->created_at,
        ];
    }
}
