<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Enums\AccommodationOccupancyType;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class EventAccommodationOption extends Model
{
    use HasEventUuid;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'event_id',
        'name',
        'description',
        'location',
        'address',
        'distance_from_venue',
        'room_type',
        'amenities',
        'image_media_ids',
        'occupancy_type',
        'capacity',
        'unit_count',
        'price',
        'price_basis',
        'price_per_night',
        'private_price',
        'shared_price',
        'currency',
        'min_nights',
        'max_nights',
        'check_in_info',
        'check_out_info',
        'notes',
        'require_full_occupancy',
        'is_active',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'image_media_ids' => 'array',
            'occupancy_type' => AccommodationOccupancyType::class,
            'capacity' => 'integer',
            'unit_count' => 'integer',
            'price' => 'decimal:2',
            'price_per_night' => 'decimal:2',
            'private_price' => 'decimal:2',
            'shared_price' => 'decimal:2',
            'min_nights' => 'integer',
            'max_nights' => 'integer',
            'require_full_occupancy' => 'boolean',
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

    public function allocations(): HasMany
    {
        return $this->hasMany(EventAccommodationAllocation::class, 'option_id');
    }

    public function pairings(): HasMany
    {
        return $this->hasMany(EventAccommodationPairing::class, 'option_id');
    }

    public function totalSpaces(): int
    {
        return max(1, (int) $this->capacity) * max(0, (int) $this->unit_count);
    }

    public function occupancyValue(): string
    {
        return $this->occupancy_type instanceof AccommodationOccupancyType
            ? $this->occupancy_type->value
            : (string) ($this->occupancy_type ?: 'shared');
    }

    public function roomNightPrice(?string $occupancy = null): float
    {
        $occupancy = $occupancy ?: $this->occupancyValue();
        if ($occupancy === AccommodationOccupancyType::Private->value && $this->private_price !== null) {
            return (float) $this->private_price;
        }
        if ($occupancy === AccommodationOccupancyType::Shared->value && $this->shared_price !== null) {
            return (float) $this->shared_price;
        }
        if ($this->price_per_night !== null) {
            return (float) $this->price_per_night;
        }

        return (float) ($this->price ?? 0);
    }

    public function perPersonNight(?string $occupancy = null): float
    {
        $occupancy = $occupancy ?: $this->occupancyValue();
        $room = $this->roomNightPrice($occupancy);
        if ($occupancy === AccommodationOccupancyType::Shared->value) {
            $capacity = max(1, (int) $this->capacity);

            return round($room / $capacity, 2);
        }

        return round($room, 2);
    }

    /**
     * @return array{nights: int, room_night: float, person_night: float, person_total: float, room_total: float, currency: string, occupancy: string, capacity: int}
     */
    public function quote(int $nights, ?string $occupancy = null): array
    {
        $nights = max(1, $nights);
        $occupancy = $occupancy ?: $this->occupancyValue();
        $personNight = $this->perPersonNight($occupancy);
        $roomNight = $this->roomNightPrice($occupancy);
        $capacity = max(1, (int) $this->capacity);

        return [
            'nights' => $nights,
            'room_night' => $roomNight,
            'person_night' => $personNight,
            'person_total' => round($personNight * $nights, 2),
            'room_total' => round($roomNight * $nights, 2),
            'currency' => (string) ($this->currency ?: 'USD'),
            'occupancy' => $occupancy,
            'capacity' => $capacity,
        ];
    }

    public static function nightsBetween(mixed $checkIn, mixed $checkOut): int
    {
        if ($checkIn === null || $checkOut === null) {
            return 1;
        }
        $start = $checkIn instanceof Carbon ? $checkIn->copy()->startOfDay() : Carbon::parse((string) $checkIn)->startOfDay();
        $end = $checkOut instanceof Carbon ? $checkOut->copy()->startOfDay() : Carbon::parse((string) $checkOut)->startOfDay();
        $nights = $start->diffInDays($end);

        return max(1, (int) $nights);
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
