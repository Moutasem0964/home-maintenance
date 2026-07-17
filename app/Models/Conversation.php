<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One conversation per order; becomes read_only at closure, kept as dispute evidence. */
class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'status'];

    protected function casts(): array
    {
        return ['status' => ConversationStatus::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
