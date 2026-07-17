<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per booked visit (inspection / repair / followup).
 * UNIQUE(technician_id, starts_at) is the DB-level double-booking guard.
 * A technician's appointments ARE his calendar — no separate availability table.
 */
class Appointment extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'technician_id', 'type', 'starts_at', 'ends_at', 'status', 'reminder_sent_at'];

    protected function casts(): array
    {
        return [
            'type' => AppointmentType::class,
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
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
}
