<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Counselling\Enums\ServiceFormat;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingService extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_services';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'category_id',
    'title',
    'slug',
    'description',
    'short_description',
    'icon',
    'banner_media_id',
    'duration_minutes',
    'format',
    'google_meet_link',
    'zoom_link',
    'teams_link',
    'office_address',
    'maximum_sessions',
    'requires_approval',
    'requires_payment',
    'is_free',
    'visitor_price',
    'member_price',
    'currency',
    'is_visible',
    'is_featured',
    'sort_order',
    'seo_title',
    'seo_description',
    'status',
    'metadata',
    'created_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'format' => ServiceFormat::class,
      'duration_minutes' => 'integer',
      'maximum_sessions' => 'integer',
      'requires_approval' => 'boolean',
      'requires_payment' => 'boolean',
      'is_free' => 'boolean',
      'visitor_price' => 'decimal:2',
      'member_price' => 'decimal:2',
      'is_visible' => 'boolean',
      'is_featured' => 'boolean',
      'sort_order' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(CounsellingCategory::class, 'category_id');
  }

  public function bannerMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'banner_media_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function cases(): HasMany
  {
    return $this->hasMany(CounsellingCase::class, 'service_id');
  }

  public function payments(): HasMany
  {
    return $this->hasMany(CounsellingPayment::class, 'service_id');
  }
}
