<?php

namespace App\Models;

use App\Enums\DispatchOfferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * System→technician job offer ("take this job?") — NOT the price quote.
 * Audit/history of assignment attempts; the atomic accept itself is a
 * conditional state transition on the orders row (AssignmentService).
 *
 * @property DispatchOfferStatus $status
 * @property int $attempts
 * @property Carbon $expires_at
 */
class DispatchOffer extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'technician_id', 'status', 'attempts', 'decline_reason', 'offered_at', 'responded_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'status' => DispatchOfferStatus::class,
            'attempts' => 'integer',
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
