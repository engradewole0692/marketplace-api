<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventAccommodationPairing extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'option_id',
        'check_in_date',
        'check_out_date',
        'nights',
        'billable_check_in_date',
        'billable_check_out_date',
        'billable_nights',
        'status',
        'requested_by_registration_id',
        'confirmed_at',
        'declined_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'declined_at' => 'datetime',
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'nights' => 'integer',
            'billable_check_in_date' => 'date',
            'billable_check_out_date' => 'date',
            'billable_nights' => 'integer',
            'meta' => 'array',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'requested_by_registration_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(EventAccommodationPairingMember::class, 'pairing_id');
    }
}
