<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PromoAppliesTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'discount_type', 'discount_value', 'applies_to', 'max_uses', 'expires_at', 'is_active'];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'applies_to' => PromoAppliesTo::class,
            'discount_value' => 'decimal:2',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }
}
