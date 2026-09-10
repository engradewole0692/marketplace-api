<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegService extends Model
{
    use HasEventUuid;

    protected $table = 'event_reg_services';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'registration_id',
        'type',
        'status',
        'option_id',
        'allocation_id',
        'details',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EventRegServiceType::class,
            'status' => EventRegServiceStatus::class,
            'details' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(EventAccommodationOption::class, 'option_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(EventAccommodationAllocation::class, 'allocation_id');
    }
}
