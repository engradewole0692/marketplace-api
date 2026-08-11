<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsSchool extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_schools';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'slug', 'title', 'subtitle', 'summary', 'description', 'status', 'sort_order',
    'member_price', 'public_price', 'currency', 'certificate_enabled', 'sequential_progression',
    'cover_media_id', 'thumbnail_media_id', 'metadata', 'published_at',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => SchoolStatus::class,
      'member_price' => 'decimal:2',
      'public_price' => 'decimal:2',
      'certificate_enabled' => 'boolean',
      'sequential_progression' => 'boolean',
      'metadata' => 'array',
      'published_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function courses(): HasMany
  {
    return $this->hasMany(Course::class, 'school_id');
  }

  public function programModules(): HasMany
  {
    return $this->hasMany(LmsProgramModule::class, 'school_id')->orderBy('sort_order');
  }

  public function enrollments(): HasMany
  {
    return $this->hasMany(SchoolEnrollment::class, 'school_id');
  }

  public function coverMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'cover_media_id');
  }

  public function thumbnailMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'thumbnail_media_id');
  }

  public function scopePublished($query)
  {
    return $query->where('status', SchoolStatus::Published->value);
  }
}
