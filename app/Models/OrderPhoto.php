<?php

namespace App\Models;

use App\Enums\OrderPhotoKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property OrderPhotoKind $kind
 * @property string $path
 */
class OrderPhoto extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // immutable evidence record

    protected $fillable = ['order_id', 'kind', 'path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['kind' => OrderPhotoKind::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
