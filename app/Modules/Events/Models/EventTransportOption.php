<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventTransportOption extends Model
{
    use HasEventUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'event_id',
        'name',
        'route',
        'description',
        'price',
        'price_basis',
        'currency',
        'service_date',
        'time_windows',
        'vehicle_type',
        'vehicle_name',
        'make_model',
        'passenger_capacity',
        'luggage_capacity',
        'image_media_ids',
        'vehicle_details',
        'pickup_instructions',
        'dropoff_instructions',
        'status',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'service_date' => 'date',
            'time_windows' => 'array',
            'image_media_ids' => 'array',
            'passenger_capacity' => 'integer',
            'luggage_capacity' => 'integer',
            'is_active' => 'boolean',
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

    public function trips(): HasMany
    {
        return $this->hasMany(EventTransportTrip::class, 'option_id');
    }

    public function calculateAmount(int $passengers = 1, int $trips = 1): float
    {
        $price = (float) $this->price;
        $basis = (string) ($this->price_basis ?: 'per_trip');

        return match ($basis) {
            'per_passenger' => round($price * max(1, $passengers) * max(1, $trips), 2),
            'per_vehicle', 'per_day', 'custom' => round($price * max(1, $trips), 2),
            default => round($price * max(1, $trips), 2),
        };
    }

    /**
     * @return list<string>
     */
    public function imageUrls(): array
    {
        $ids = is_array($this->image_media_ids) ? $this->image_media_ids : [];
        if ($ids === []) {
            return [];
        }
        $numeric = [];
        $uuids = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $numeric[] = (int) $id;
            } elseif (is_string($id) && $id !== '') {
                $uuids[] = $id;
            }
        }

        return CmsMedia::query()
            ->where(function ($query) use ($numeric, $uuids): void {
                if ($numeric !== []) {
                    $query->orWhereIn('id', $numeric);
                }
                if ($uuids !== []) {
                    $query->orWhereIn('uuid', $uuids);
                }
            })
            ->get()
            ->map(fn (CmsMedia $media) => $media->url())
            ->filter()
            ->values()
            ->all();
    }
}
