<?php

namespace App\Models;

use App\Enums\BalanceType;
use App\Enums\TxnType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Double-entry ledger — the single source of truth for money (SRS note 1).
 * Append-only: rows are never updated or deleted; corrections are written
 * as reversal entries. reference is unique to block duplicate writes.
 */
class WalletTransaction extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['wallet_id', 'payment_id', 'order_id', 'type', 'balance_type', 'amount', 'reference', 'description'];

    protected function casts(): array
    {
        return [
            'type' => TxnType::class,
            'balance_type' => BalanceType::class,
            'amount' => 'decimal:2',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Append-only guard. */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
