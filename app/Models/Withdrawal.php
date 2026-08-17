<?php

namespace App\Models;

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auto-blocked while the technician has a disputed order (WithdrawalService rule).
 *
 * @property WithdrawalStatus $status
 * @property numeric-string $amount
 * @property string|null $destination_details
 * @property string|null $destination_name
 */
class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = ['technician_id', 'amount', 'destination_details', 'destination_name', 'status', 'receipt_url', 'processed_by'];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'amount' => 'decimal:2',
            'destination_details' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
