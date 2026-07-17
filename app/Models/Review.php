<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One review per order (unique). Feeds technicians.rating_avg via background job. */
class Review extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'client_id', 'technician_id', 'cleanliness', 'quality', 'price_rating', 'comment', 'price_anomaly_flag'];

    protected function casts(): array
    {
        return ['price_anomaly_flag' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
