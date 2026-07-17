<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianPortfolio extends Model
{
    use HasFactory;

    protected $fillable = ['technician_id', 'image_url', 'description'];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
