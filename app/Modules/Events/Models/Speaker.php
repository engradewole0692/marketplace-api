<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Speaker extends Model
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
    'title',
    'organization',
    'bio',
    'photo_media_id',
    'email',
    'phone',
    'website_url',
    'status',
    'metadata',
    'created_by_user_id',
    'updated_by_user_id',
    'deleted_by_user_id',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'photo_url',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
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

  public function photo(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'photo_media_id');
  }

  public function events(): BelongsToMany
  {
    return $this->belongsToMany(Event::class)
      ->using(EventSpeaker::class)
      ->withPivot(['role', 'sort_order'])
      ->withTimestamps();
  }

  public function sessions(): HasMany
  {
    return $this->hasMany(EventSession::class);
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

  public function getPhotoUrlAttribute(): ?string
  {
    return $this->photo?->url();
  }
}
