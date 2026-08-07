<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventCategory extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'ministry_id',
    'name',
    'slug',
    'description',
    'status',
    'sort_order',
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
      'sort_order' => 'integer',
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

  public function ministry(): BelongsTo
  {
    return $this->belongsTo(CmsMinistry::class, 'ministry_id');
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
