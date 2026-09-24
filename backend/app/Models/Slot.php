<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Slot extends Model
{
    protected $fillable = [
        'day_of_week',
        'start_time',
        'capacity',
        'trainer',
        'status',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'capacity' => 'integer',
    ];

    protected $attributes = [
        'trainer' => null,
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function scopeAbierta(Builder $query): Builder
    {
        return $query->where('status', 'abierta');
    }

    public function scopeBloqueada(Builder $query): Builder
    {
        return $query->where('status', 'bloqueada');
    }

    public function occupationForWeek(string $weekStart): int
    {
        return $this->reservations()
            ->where('week_start', $weekStart)
            ->count();
    }

    public function isAbierta(): bool
    {
        return $this->status === 'abierta';
    }

    public function realDateTimeFor(string $weekStart): Carbon
    {
        return Carbon::parse($weekStart, 'Europe/Madrid')->addDays($this->day_of_week - 1)->setTimeFromTimeString($this->start_time);
    }
}
