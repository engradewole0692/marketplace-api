<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\SeatClassificationScope;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSeatClassification extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'scope',
        'match_key',
        'label',
        'counts_toward_seating',
    ];

    protected function casts(): array
    {
        return [
            'scope' => SeatClassificationScope::class,
            'counts_toward_seating' => 'boolean',
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
}
