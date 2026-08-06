<?php

namespace App\Models;

use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianFlagReason;
use App\Enums\TechnicianFlagStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reliability offense queued for admin assessment.
 *
 * @property TechnicianFlagReason $reason
 * @property TechnicianFlagStatus $status
 * @property TechnicianFlagOutcome|null $outcome
 * @property int $technician_id
 */
class TechnicianFlag extends Model
{
    use HasFactory;

    protected $fillable = ['technician_id', 'order_id', 'reason', 'status', 'outcome', 'note', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'reason' => TechnicianFlagReason::class,
            'status' => TechnicianFlagStatus::class,
            'outcome' => TechnicianFlagOutcome::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
