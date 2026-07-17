<?php

namespace App\Models;

use App\Enums\TechnicianStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TechnicianStatus $status
 * @property int|null $daily_order_limit
 */
class Technician extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'status', 'is_available', 'current_lat', 'current_lng',
        'id_doc_url', 'selfie_url', 'criminal_record_url', 'proof_url',
        'charter_accepted_at', 'daily_order_limit',
    ];

    protected function casts(): array
    {
        return [
            'status' => TechnicianStatus::class,
            'is_available' => 'boolean',
            'current_lat' => 'decimal:7',
            'current_lng' => 'decimal:7',
            'rating_avg' => 'decimal:2',
            'acceptance_rate' => 'decimal:2',
            // Sensitive verification documents — encrypted at rest (SRS note 10)
            'id_doc_url' => 'encrypted',
            'selfie_url' => 'encrypted',
            'criminal_record_url' => 'encrypted',
            'proof_url' => 'encrypted',
            'charter_accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'technician_services')->withTimestamps();
    }

    public function portfolios(): HasMany
    {
        return $this->hasMany(TechnicianPortfolio::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function dispatchOffers(): HasMany
    {
        return $this->hasMany(DispatchOffer::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function canAcceptMore(): bool
    {
        if ($this->status !== TechnicianStatus::Probation || $this->daily_order_limit === null) {
            return true;
        }

        return $this->orders()->whereDate('created_at', today())->count() < $this->daily_order_limit;
    }
}
