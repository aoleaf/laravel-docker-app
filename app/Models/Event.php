<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'title',
        'description',
        'venue',
        'starts_at',
        'capacity',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'capacity' => 'integer',
    ];

    // hasMany: イベント(1) → 予約(多)
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    // 予約済み人数の合計（withSum()済みならその値を使い、無ければ集計クエリを投げる）
    public function reservedSeats(): int
    {
        return (int) ($this->reservations_sum_number_of_people
            ?? $this->reservations()->sum('number_of_people'));
    }

    // 残席数
    public function remainingSeats(): int
    {
        return max(0, $this->capacity - $this->reservedSeats());
    }

    public function isFull(): bool
    {
        return $this->remainingSeats() === 0;
    }

    // 開催済みかどうか
    public function hasEnded(): bool
    {
        return $this->starts_at->isPast();
    }
}
