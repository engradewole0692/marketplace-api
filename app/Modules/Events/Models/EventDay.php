<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class EventDay extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'day_index',
        'date',
        'label',
        'starts_at',
        'ends_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'day_index' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EventDayAttendance::class, 'event_day_id');
    }

    public function isCurrent(?Carbon $now = null): bool
    {
        $now ??= now();
        $date = $this->date?->toDateString();
        if ($date === null) {
            return false;
        }

        return $now->toDateString() === $date;
    }
}
