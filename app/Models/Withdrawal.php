<?php

namespace App\Models;

use App\Enums\WithdrawalMethod;
use App\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Auto-blocked while the technician has a disputed order (WithdrawalService rule). */
class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = ['technician_id', 'amount', 'method', 'destination_details', 'status', 'receipt_url', 'processed_by'];

    protected function casts(): array
    {
        return [
            'method' => WithdrawalMethod::class,
            'status' => WithdrawalStatus::class,
            'amount' => 'decimal:2',
            'destination_details' => 'encrypted',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
