<?php

namespace App\Models;

use App\Enums\BalanceType;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Balances are derived caches — the ledger (wallet_transactions) is the
 * source of truth. recalculate() must agree with the cached columns;
 * any drift is a bug in a money path.
 *
 * @property numeric-string $available_balance
 * @property numeric-string $held_balance
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

    /** @return HasMany<WalletTransaction, $this> */
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

    public function decreaseAvailableBalance(string $amount): void
    {
        if (bccomp($this->available_balance, $amount, 2) < 0) {
            throw new InsufficientBalanceException("Insufficient available balance: {$this->available_balance} < {$amount}");
        }

        $this->available_balance = bcsub($this->available_balance, $amount, 2);
        $this->save();
    }

    public function increaseAvailableBalance(string $amount): void
    {
        $this->available_balance = bcadd($this->available_balance, $amount, 2);
        $this->save();
    }

    public function decreaseHeldBalance(string $amount): void
    {
        if (bccomp($this->held_balance, $amount, 2) < 0) {
            throw new InsufficientBalanceException("Insufficient held balance: {$this->held_balance} < {$amount}");
        }

        $this->held_balance = bcsub($this->held_balance, $amount, 2);
        $this->save();
    }

    public function increaseHeldBalance(string $amount): void
    {
        $this->held_balance = bcadd($this->held_balance, $amount, 2);
        $this->save();
    }
}
