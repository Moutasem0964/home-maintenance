<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id', 'technician_id', 'service_category_id', 'address_id', 'parent_order_id',
        'lat', 'lng', 'description', 'kind', 'type', 'scheduled_at', 'status',
        'dispute_deadline_at', 'warranty_until', 'commission_rate', 'commission_amount', 'inspection_fee',
    ];

    /** closure_code is server-side only — never expose it through the API. */
    protected $hidden = ['closure_code'];

    protected function casts(): array
    {
        return [
            'kind' => OrderKind::class,
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'scheduled_at' => 'datetime',
            'closure_code' => 'encrypted', // verified server-side only (SRS note 4)
            'closure_expires_at' => 'datetime',
            'closure_verified_at' => 'datetime',
            'dispute_deadline_at' => 'datetime',
            'warranty_until' => 'datetime',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'inspection_fee' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function parentOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function childOrders(): HasMany
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function dispatchOffers(): HasMany
    {
        return $this->hasMany(DispatchOffer::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function dispute(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }
}
