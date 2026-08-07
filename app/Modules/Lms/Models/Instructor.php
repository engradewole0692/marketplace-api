<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Instructor extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_instructors';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'user_id', 'name', 'slug', 'title', 'bio', 'photo_media_id',
    'email', 'website_url', 'status', 'metadata', 'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => CatalogStatus::class,
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

  public function courses(): BelongsToMany
  {
    return $this->belongsToMany(Course::class, 'lms_course_instructor', 'instructor_id', 'course_id')
      ->withPivot(['is_primary', 'sort_order', 'role_label']);
  }
}
