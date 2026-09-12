<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTravelRequest extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'registration_id',
        'service_id',
        'payment_id',
        'trip_type',
        'origin',
        'destination',
        'departure_date',
        'preferred_departure_time',
        'return_date',
        'preferred_return_time',
        'airline_preference',
        'travel_class',
        'passengers',
        'passenger_names',
        'notes',
        'quote_amount',
        'currency',
        'status',
        'quoted_at',
        'booked_at',
        'cancelled_at',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'passengers' => 'integer',
            'passenger_names' => 'array',
            'quote_amount' => 'decimal:2',
            'quoted_at' => 'datetime',
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(EventRegService::class, 'service_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(EventRegistrationPayment::class, 'payment_id');
    }
}
