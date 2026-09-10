<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAccommodationAllocation extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'option_id',
        'registration_id',
        'pairing_id',
        'spaces',
        'status',
        'check_in_date',
        'check_out_date',
        'confirmed_at',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'spaces' => 'integer',
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'confirmed_at' => 'datetime',
            'details' => 'array',
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

    public function option(): BelongsTo
    {
        return $this->belongsTo(EventAccommodationOption::class, 'option_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function pairing(): BelongsTo
    {
        return $this->belongsTo(EventAccommodationPairing::class, 'pairing_id');
    }
}
