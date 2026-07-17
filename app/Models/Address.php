<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'label', 'lat', 'lng', 'building_no', 'floor', 'notes'];

    protected function casts(): array
    {
        return ['lat' => 'decimal:7', 'lng' => 'decimal:7'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
