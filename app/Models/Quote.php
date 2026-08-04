<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Enums\QuoteType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Technician→client price offer, sent after diagnosis. Expires automatically.
 *
 * @property QuoteStatus $status
 * @property QuoteType $type
 * @property Carbon $expires_at
 */
class Quote extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'technician_id', 'type', 'labor_cost', 'warranty_days', 'justification', 'status', 'expires_at'];

    protected function casts(): array
    {
        return [
            'type' => QuoteType::class,
            'status' => QuoteStatus::class,
            'labor_cost' => 'decimal:2',
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

    public function parts(): HasMany
    {
        return $this->hasMany(QuotePart::class);
    }

    public function total(): float
    {
        return (float) $this->labor_cost + (float) $this->parts->sum('price');
    }
}
