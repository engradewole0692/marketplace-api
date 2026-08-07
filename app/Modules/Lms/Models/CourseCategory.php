<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseCategory extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_categories';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'name', 'slug', 'description', 'seo_title', 'seo_description', 'parent_id',
    'sort_order', 'status', 'is_visible', 'icon', 'cover_media_id',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => CatalogStatus::class,
      'sort_order' => 'integer',
      'is_visible' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function parent(): BelongsTo
  {
    return $this->belongsTo(self::class, 'parent_id');
  }

  public function children(): HasMany
  {
    return $this->hasMany(self::class, 'parent_id');
  }

  public function coverMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'cover_media_id');
  }

  public function courses(): HasMany
  {
    return $this->hasMany(Course::class, 'category_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
