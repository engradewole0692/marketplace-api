<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Counsellor extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_counsellors';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'user_id',
    'display_name',
    'slug',
    'biography',
    'specializations',
    'languages',
    'photo_media_id',
    'google_meet_link',
    'zoom_link',
    'teams_link',
    'max_daily_sessions',
    'is_active',
    'sort_order',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'specializations' => 'array',
      'languages' => 'array',
      'max_daily_sessions' => 'integer',
      'is_active' => 'boolean',
      'sort_order' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function photoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'photo_media_id');
  }

  public function availability(): HasMany
  {
    return $this->hasMany(CounsellorAvailability::class, 'counsellor_id');
  }

  public function cases(): HasMany
  {
    return $this->hasMany(CounsellingCase::class, 'counsellor_id');
  }

  public function appointments(): HasMany
  {
    return $this->hasMany(CounsellingAppointment::class, 'counsellor_id');
  }
}
