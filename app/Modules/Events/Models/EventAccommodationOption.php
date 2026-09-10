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
        'amenities',
        'image_media_ids',
        'occupancy_type',
        'capacity',
        'unit_count',
        'price',
        'price_basis',
        'currency',
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

    public function totalSpaces(): int
    {
        return max(1, (int) $this->capacity) * max(0, (int) $this->unit_count);
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
