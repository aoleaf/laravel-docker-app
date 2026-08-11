<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $fillable = [
        'event_id',
        'name',
        'email',
        'number_of_people',
        'reserved_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
        'number_of_people' => 'integer',
    ];

    // belongsTo: 予約(多) → イベント(1)
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
