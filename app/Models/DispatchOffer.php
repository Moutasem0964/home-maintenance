<?php

namespace App\Models;

use App\Enums\DispatchOfferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * System→technician job offer ("take this job?") — NOT the price quote.
 * Audit/history of assignment attempts; the atomic accept itself is a
 * conditional state transition on the orders row (AssignmentService).
 */
class DispatchOffer extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'technician_id', 'status', 'decline_reason', 'offered_at', 'responded_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'status' => DispatchOfferStatus::class,
            'offered_at' => 'datetime',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
