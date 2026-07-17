<?php

namespace App\Models;

use App\Enums\TopUpStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopUp extends Model
{
    use HasFactory;

    protected $fillable = ['wallet_id', 'amount', 'gateway_reference', 'status'];

    protected function casts(): array
    {
        return ['status' => TopUpStatus::class, 'amount' => 'decimal:2'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
