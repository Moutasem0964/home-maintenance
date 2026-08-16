<?php

namespace App\Models;

use App\Enums\TopUpStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TopUpStatus $status
 * @property numeric-string $amount
 */
class TopUp extends Model
{
    use HasFactory;

    protected $fillable = ['wallet_id', 'amount', 'gateway_reference', 'receipt_url', 'status', 'reviewed_by'];

    protected function casts(): array
    {
        return ['status' => TopUpStatus::class, 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
