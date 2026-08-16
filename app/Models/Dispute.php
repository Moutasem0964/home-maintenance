<?php

namespace App\Models;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\OrderPhotoKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Raising a dispute freezes the escrow release (one transaction, competing on the order lock).
 *
 * @property int $order_id
 * @property DisputeReason $reason
 * @property DisputeStatus $status
 * @property DisputeResolution|null $resolution
 */
class Dispute extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'raised_by', 'reason', 'description', 'status', 'resolution', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'reason' => DisputeReason::class,
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Dispute-evidence photos, matched to the same order and the dispute kind. */
    public function photos(): HasMany
    {
        return $this->hasMany(OrderPhoto::class, 'order_id', 'order_id')->where('kind', OrderPhotoKind::Dispute);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
