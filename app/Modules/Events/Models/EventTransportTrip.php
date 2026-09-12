<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventTransportTrip extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'registration_id',
        'option_id',
        'service_id',
        'payment_id',
        'route',
        'pickup_location',
        'dropoff_location',
        'trip_date',
        'trip_time',
        'passengers',
        'luggage',
        'special_requirements',
        'flight_info',
        'amount',
        'currency',
        'status',
        'assigned_vehicle',
        'assigned_driver_user_id',
        'assigned_by_user_id',
        'assigned_at',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'passengers' => 'integer',
            'amount' => 'decimal:2',
            'flight_info' => 'array',
            'assigned_at' => 'datetime',
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

    public function option(): BelongsTo
    {
        return $this->belongsTo(EventTransportOption::class, 'option_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(EventRegService::class, 'service_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(EventRegistrationPayment::class, 'payment_id');
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }
}
