<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bell-icon notification log. notifiable_type/id is a DOMAIN reference
 * (order / dispute / payment / ticket) — the mobile app decides the screen.
 * Named AppNotification to avoid clashing with Laravel's Notification facade.
 */
class AppNotification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $fillable = ['user_id', 'category', 'title', 'body', 'notifiable_type', 'notifiable_id', 'read_at'];

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
