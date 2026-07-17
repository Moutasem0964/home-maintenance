<?php

namespace App\Models;

use App\Enums\OrderEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit trail. Never updated, never deleted. */
class OrderEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'actor_id', 'event_type', 'metadata'];

    protected function casts(): array
    {
        return ['event_type' => OrderEventType::class, 'metadata' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Append-only guard: block updates at the model level. */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
