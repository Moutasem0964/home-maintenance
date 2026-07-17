<?php

namespace App\Models;

use App\Enums\BalanceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Balances are derived caches — the ledger (wallet_transactions) is the
 * source of truth. recalculate() must agree with the cached columns;
 * any drift is a bug in a money path.
 */
class Wallet extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'currency'];

    protected function casts(): array
    {
        return ['available_balance' => 'decimal:2', 'held_balance' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function topUps(): HasMany
    {
        return $this->hasMany(TopUp::class);
    }

    public function ledgerBalance(BalanceType $type): string
    {
        return (string) $this->transactions()
            ->where('balance_type', $type->value)
            ->sum('amount');
    }
}
