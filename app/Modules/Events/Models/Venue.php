<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Region;
use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'name',
    'slug',
    'description',
    'address_line_1',
    'address_line_2',
    'city',
    'state',
    'country_id',
    'region_id',
    'postal_code',
    'latitude',
    'longitude',
    'capacity',
    'contact_name',
    'contact_email',
    'contact_phone',
    'status',
    'metadata',
    'created_by_user_id',
    'updated_by_user_id',
    'deleted_by_user_id',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'latitude' => 'decimal:7',
      'longitude' => 'decimal:7',
      'capacity' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function scopeActive(Builder $query): Builder
  {
    return $query->where('status', 'active');
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function region(): BelongsTo
  {
    return $this->belongsTo(Region::class, 'region_id');
  }

  public function events(): HasMany
  {
    return $this->hasMany(Event::class);
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function updater(): BelongsTo
  {
    return $this->belongsTo(User::class, 'updated_by_user_id');
  }

  public function deleter(): BelongsTo
  {
    return $this->belongsTo(User::class, 'deleted_by_user_id');
  }
}
