<?php

namespace App\Models;

use App\Enums\PartClassification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotePart extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['quote_id', 'name', 'price', 'classification', 'image_url'];

    protected function casts(): array
    {
        return ['classification' => PartClassification::class, 'price' => 'decimal:2'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
